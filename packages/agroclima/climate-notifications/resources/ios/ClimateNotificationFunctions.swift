import BackgroundTasks
import Foundation
import UIKit
import UserNotifications

enum ClimateNotificationFunctions {
    private static let permissionEvent = "App\\Events\\ClimateNotificationPermissionResult"

    final class CheckPermission: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let event = parameters["event"] as? String ?? permissionEvent
            let id = parameters["id"] as? String

            ClimateNotificationPermission.currentStatus { granted in
                ClimateNotificationPermission.dispatch(event: event, id: id, granted: granted)
            }

            return BridgeResponse.success(data: [:])
        }
    }

    final class RequestPermission: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let event = parameters["event"] as? String ?? permissionEvent
            let id = parameters["id"] as? String

            UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound]) { granted, _ in
                ClimateNotificationPermission.dispatch(event: event, id: id, granted: granted)
            }

            return BridgeResponse.success(data: [:])
        }
    }

    final class SyncSchedule: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard JSONSerialization.isValidJSONObject(parameters),
                  let data = try? JSONSerialization.data(withJSONObject: parameters),
                  let schedule = String(data: data, encoding: .utf8) else {
                return BridgeResponse.error(code: "INVALID_SCHEDULE", message: "Invalid climate alert schedule")
            }

            ClimateAlertBackground.sync(schedule: schedule)

            return BridgeResponse.success(data: [:])
        }
    }

    static func initialize() {
        ClimateAlertBackground.initialize()
    }
}

private enum ClimateNotificationPermission {
    static func currentStatus(completion: @escaping (Bool) -> Void) {
        UNUserNotificationCenter.current().getNotificationSettings { settings in
            let allowed = settings.authorizationStatus == .authorized
                || settings.authorizationStatus == .provisional
                || settings.authorizationStatus == .ephemeral
            completion(allowed)
        }
    }

    static func dispatch(event: String, id: String?, granted: Bool) {
        var payload: [String: Any] = ["granted": granted]
        if let id {
            payload["id"] = id
        }

        DispatchQueue.main.async {
            LaravelBridge.shared.send?(event, payload)
        }
    }
}

private enum ClimateAlertBackground {
    private static let taskIdentifier = "dev.donmanuel.elver.climate-alert-refresh"
    private static let scheduleKey = "agroclima.climate-alerts.schedule"
    private static let alertStatesKey = "agroclima.climate-alerts.states"
    private static let defaultForecastUrl = "https://api.open-meteo.com/v1/forecast"
    private static let refreshInterval: TimeInterval = 60 * 60
    private static let metrics = [
        "temperature_2m",
        "relative_humidity_2m",
        "precipitation",
        "wind_speed_10m",
    ]
    private static let registered = BGTaskScheduler.shared.register(
        forTaskWithIdentifier: taskIdentifier,
        using: nil
    ) { task in
        guard let refreshTask = task as? BGAppRefreshTask else {
            task.setTaskCompleted(success: false)
            return
        }

        perform(refreshTask)
    }

    static func initialize() {
        guard registered else {
            return
        }

        scheduleStoredTask()
    }

    static func sync(schedule: String) {
        UserDefaults.standard.set(schedule, forKey: scheduleKey)

        guard let object = try? JSONSerialization.jsonObject(with: Data(schedule.utf8)) as? [String: Any],
              hasAlerts(in: object) else {
            cancel()
            UserDefaults.standard.set([String: [String: Any]](), forKey: alertStatesKey)
            return
        }

        reconcileStates(with: object)
        ClimateNotificationPermission.currentStatus { granted in
            if granted {
                scheduleNext()
            } else {
                cancel()
            }
        }
    }

    private static func perform(_ task: BGAppRefreshTask) {
        guard let schedule = storedSchedule(), hasAlerts(in: schedule) else {
            cancel()
            task.setTaskCompleted(success: true)
            return
        }

        scheduleNext()

        var operation: Task<Void, Never>?
        task.expirationHandler = {
            operation?.cancel()
        }

        operation = Task {
            let granted = await notificationsAllowed()
            let success = granted ? await refresh(schedule: schedule) : true

            if !granted {
                cancel()
            }

            task.setTaskCompleted(success: success)
        }
    }

    private static func refresh(schedule: [String: Any]) async -> Bool {
        guard let locations = schedule["locations"] as? [[String: Any]] else {
            return false
        }

        let forecastUrl = secureForecastUrl(schedule["forecastUrl"] as? String)
        var states = UserDefaults.standard.dictionary(forKey: alertStatesKey) as? [String: [String: Any]] ?? [:]
        var succeeded = true

        for location in locations {
            if Task.isCancelled {
                return false
            }

            guard let latitude = location["latitude"] as? Double,
                  let longitude = location["longitude"] as? Double,
                  let alerts = location["alerts"] as? [[String: Any]],
                  let current = await fetchCurrentWeather(
                    endpoint: forecastUrl,
                    latitude: latitude,
                    longitude: longitude
                  ) else {
                succeeded = false
                continue
            }

            let evaluatedAt = Date().timeIntervalSince1970 * 1_000

            for alert in alerts {
                guard let id = alert["id"] as? String,
                      let signature = alert["signature"] as? String,
                      let metric = alert["metric"] as? String,
                      let operatorValue = alert["operator"] as? String,
                      let threshold = alert["threshold"] as? Double else {
                    continue
                }

                guard isActive(id: id, signature: signature) else {
                    continue
                }

                let state = alertState(
                    current[metric],
                    comparison: operatorValue,
                    threshold: threshold
                )
                let saved = states[id]
                let previousState = saved?["signature"] as? String == signature
                    ? saved?["state"] as? String
                    : alert["lastState"] as? String

                if state == "exceeded", previousState != "exceeded",
                   isActive(id: id, signature: signature) {
                    await showNotification(location: location, alert: alert)
                }

                states[id] = [
                    "signature": signature,
                    "state": state,
                    "evaluatedAt": evaluatedAt,
                ]
            }
        }

        let activeAlerts = activeAlertSignatures()
        let activeStates = states.filter { id, state in
            activeAlerts[id] == state["signature"] as? String
        }
        UserDefaults.standard.set(activeStates, forKey: alertStatesKey)
        return succeeded
    }

    private static func fetchCurrentWeather(
        endpoint: URL,
        latitude: Double,
        longitude: Double
    ) async -> [String: Double]? {
        guard (-90...90).contains(latitude), (-180...180).contains(longitude),
              var components = URLComponents(url: endpoint, resolvingAgainstBaseURL: false) else {
            return nil
        }

        components.queryItems = [
            URLQueryItem(name: "latitude", value: String(latitude)),
            URLQueryItem(name: "longitude", value: String(longitude)),
            URLQueryItem(name: "current", value: metrics.joined(separator: ",")),
            URLQueryItem(name: "timezone", value: "auto"),
            URLQueryItem(name: "timeformat", value: "unixtime"),
        ]

        guard let url = components.url else {
            return nil
        }

        var request = URLRequest(url: url)
        request.timeoutInterval = 10

        do {
            let (data, response) = try await URLSession.shared.data(for: request)
            guard let response = response as? HTTPURLResponse,
                  (200...299).contains(response.statusCode),
                  let payload = try JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let current = payload["current"] as? [String: Any] else {
                return nil
            }

            var values: [String: Double] = [:]

            for metric in metrics {
                if let value = current[metric] as? Double, value.isFinite {
                    values[metric] = value
                }
            }

            return values
        } catch {
            return nil
        }
    }

    private static func alertState(_ value: Double?, comparison: String, threshold: Double) -> String {
        guard let value, value.isFinite, threshold.isFinite else {
            return "no_data"
        }

        let exceeded = comparison == "above" ? value > threshold : comparison == "below" ? value < threshold : false
        return exceeded ? "exceeded" : "normal"
    }

    private static func showNotification(location: [String: Any], alert: [String: Any]) async {
        let content = UNMutableNotificationContent()
        let name = location["name"] as? String ?? "tu ubicación"
        let label = alert["label"] as? String ?? "Una variable climática"
        let unit = alert["unit"] as? String ?? ""
        let operatorPhrase = alert["operator"] as? String == "above" ? "superó" : "bajó de"
        let threshold = alert["threshold"] as? Double ?? 0

        content.title = "Alerta climática"
        content.body = String(
            format: "%@ %@ %.1f %@ en %@.",
            locale: Locale(identifier: "es_NI"),
            label,
            operatorPhrase,
            threshold,
            unit,
            name
        )
        content.sound = .default

        let request = UNNotificationRequest(
            identifier: "climate-alert-\(alert["id"] as? String ?? UUID().uuidString)",
            content: content,
            trigger: nil
        )
        do {
            try await UNUserNotificationCenter.current().add(request)
        } catch {
            NSLog("Unable to post climate alert notification: %@", error.localizedDescription)
        }
    }

    private static func reconcileStates(with schedule: [String: Any]) {
        guard let locations = schedule["locations"] as? [[String: Any]] else {
            return
        }

        let previous = UserDefaults.standard.dictionary(forKey: alertStatesKey) as? [String: [String: Any]] ?? [:]
        var next: [String: [String: Any]] = [:]

        for location in locations {
            guard let alerts = location["alerts"] as? [[String: Any]] else {
                continue
            }

            for alert in alerts {
                guard let id = alert["id"] as? String,
                      let signature = alert["signature"] as? String else {
                    continue
                }

                let timestamp = (alert["lastEvaluatedAt"] as? NSNumber)?.doubleValue ?? 0
                let saved = previous[id]
                if saved?["signature"] as? String != signature
                    || timestamp > (saved?["evaluatedAt"] as? Double ?? 0) {
                    next[id] = [
                        "signature": signature,
                        "state": alert["lastState"] as? String ?? "",
                        "evaluatedAt": timestamp,
                    ]
                } else if let saved {
                    next[id] = saved
                }
            }
        }

        UserDefaults.standard.set(next, forKey: alertStatesKey)
    }

    private static func hasAlerts(in schedule: [String: Any]) -> Bool {
        (schedule["locations"] as? [[String: Any]])?.contains {
            !($0["alerts"] as? [[String: Any]] ?? []).isEmpty
        } ?? false
    }

    private static func isActive(id: String, signature: String) -> Bool {
        activeAlertSignatures()[id] == signature
    }

    private static func activeAlertSignatures() -> [String: String] {
        guard let schedule = storedSchedule(),
              let locations = schedule["locations"] as? [[String: Any]] else {
            return [:]
        }

        var signatures: [String: String] = [:]
        for location in locations {
            for alert in (location["alerts"] as? [[String: Any]] ?? []) {
                guard let id = alert["id"] as? String,
                      let signature = alert["signature"] as? String else {
                    continue
                }
                signatures[id] = signature
            }
        }

        return signatures
    }

    private static func storedSchedule() -> [String: Any]? {
        guard let raw = UserDefaults.standard.string(forKey: scheduleKey),
              let data = raw.data(using: .utf8) else {
            return nil
        }

        return try? JSONSerialization.jsonObject(with: data) as? [String: Any]
    }

    private static func scheduleStoredTask() {
        guard let schedule = storedSchedule(), hasAlerts(in: schedule) else {
            return
        }

        ClimateNotificationPermission.currentStatus { granted in
            if granted {
                scheduleNext()
            }
        }
    }

    private static func scheduleNext() {
        let request = BGAppRefreshTaskRequest(identifier: taskIdentifier)
        request.earliestBeginDate = Date(timeIntervalSinceNow: refreshInterval)

        do {
            try BGTaskScheduler.shared.submit(request)
        } catch {
            NSLog("Unable to schedule climate alert refresh: %@", error.localizedDescription)
        }
    }

    private static func cancel() {
        BGTaskScheduler.shared.cancel(taskRequestWithIdentifier: taskIdentifier)
    }

    private static func notificationsAllowed() async -> Bool {
        await withCheckedContinuation { continuation in
            ClimateNotificationPermission.currentStatus { granted in
                continuation.resume(returning: granted)
            }
        }
    }

    private static func secureForecastUrl(_ value: String?) -> URL {
        let candidate = URL(string: value ?? defaultForecastUrl)
        if let candidate, candidate.scheme == "https", candidate.host != nil {
            return candidate
        }

        return URL(string: defaultForecastUrl)!
    }
}
