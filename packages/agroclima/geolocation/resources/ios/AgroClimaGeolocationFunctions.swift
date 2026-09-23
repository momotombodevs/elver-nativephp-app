import CoreLocation
import Foundation

enum AgroClimaGeolocationFunctions {
    private static let locationEvent = "Native\\Mobile\\Events\\Geolocation\\LocationReceived"
    private static let permissionStatusEvent =
        "Native\\Mobile\\Events\\Geolocation\\PermissionStatusReceived"
    private static let permissionRequestEvent =
        "Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult"

    final class GetCurrentPosition: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let request = PositionRequest(
                id: parameters["id"] as? String,
                event: parameters["event"] as? String ?? locationEvent,
                fineAccuracy: parameters["fineAccuracy"] as? Bool ?? false
            )

            DispatchQueue.main.async {
                GeolocationCoordinator.shared.getCurrentPosition(request)
            }

            return BridgeResponse.success(data: [:])
        }
    }

    final class CheckPermissions: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let event = parameters["event"] as? String ?? permissionStatusEvent
            let id = parameters["id"] as? String

            DispatchQueue.main.async {
                GeolocationCoordinator.shared.checkPermissions(event: event, id: id)
            }

            return BridgeResponse.success(data: [:])
        }
    }

    final class RequestPermissions: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let request = PermissionRequest(
                id: parameters["id"] as? String,
                event: parameters["event"] as? String ?? permissionRequestEvent
            )

            DispatchQueue.main.async {
                GeolocationCoordinator.shared.requestPermissions(request)
            }

            return BridgeResponse.success(data: [:])
        }
    }
}

private struct PositionRequest {
    let id: String?
    let event: String
    let fineAccuracy: Bool
}

private struct PermissionRequest {
    let id: String?
    let event: String
}

private final class GeolocationCoordinator: NSObject, CLLocationManagerDelegate {
    static let shared = GeolocationCoordinator()

    private let manager = CLLocationManager()
    private var positionRequests: [PositionRequest] = []
    private var permissionRequests: [PermissionRequest] = []
    private var timeout: DispatchWorkItem?
    private var locationRequestInFlight = false

    private override init() {
        super.init()
        manager.delegate = self
    }

    func getCurrentPosition(_ request: PositionRequest) {
        switch manager.authorizationStatus {
        case .notDetermined:
            positionRequests.append(request)
            manager.requestWhenInUseAuthorization()
        case .authorizedAlways, .authorizedWhenInUse:
            positionRequests.append(request)
            startLocationRequest()
        case .denied:
            dispatchLocationError(request, message: "El permiso de ubicación está denegado.")
        case .restricted:
            dispatchLocationError(request, message: "La ubicación está restringida en este dispositivo.")
        @unknown default:
            dispatchLocationError(request, message: "No fue posible determinar el permiso de ubicación.")
        }
    }

    func checkPermissions(event: String, id: String?) {
        dispatchPermissionState(event: event, id: id, requestResult: false)
    }

    func requestPermissions(_ request: PermissionRequest) {
        if manager.authorizationStatus == .notDetermined {
            permissionRequests.append(request)
            manager.requestWhenInUseAuthorization()
            return
        }

        dispatchPermissionState(event: request.event, id: request.id, requestResult: true)
    }

    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        guard manager.authorizationStatus != .notDetermined else {
            return
        }

        permissionRequests.forEach { request in
            dispatchPermissionState(event: request.event, id: request.id, requestResult: true)
        }
        permissionRequests.removeAll()

        switch manager.authorizationStatus {
        case .authorizedAlways, .authorizedWhenInUse:
            startLocationRequest()
        case .denied:
            failPositions("El permiso de ubicación fue denegado.")
        case .restricted:
            failPositions("La ubicación está restringida en este dispositivo.")
        case .notDetermined:
            break
        @unknown default:
            failPositions("No fue posible determinar el permiso de ubicación.")
        }
    }

    private func startLocationRequest() {
        guard !positionRequests.isEmpty, !locationRequestInFlight else {
            return
        }

        manager.desiredAccuracy = positionRequests.contains(where: \.fineAccuracy)
            ? kCLLocationAccuracyBest
            : kCLLocationAccuracyKilometer
        locationRequestInFlight = true
        manager.requestLocation()

        let timeout = DispatchWorkItem { [weak self] in
            self?.failPositions("No fue posible obtener la ubicación a tiempo.")
        }
        self.timeout = timeout
        DispatchQueue.main.asyncAfter(deadline: .now() + 15, execute: timeout)
    }

    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard let location = locations.last else {
            failPositions("El dispositivo no devolvió una ubicación válida.")
            return
        }

        timeout?.cancel()
        timeout = nil
        locationRequestInFlight = false

        let requests = positionRequests
        positionRequests.removeAll()

        requests.forEach { request in
            var payload: [String: Any] = [
                "success": true,
                "latitude": location.coordinate.latitude,
                "longitude": location.coordinate.longitude,
                "accuracy": location.horizontalAccuracy,
                "timestamp": Int(location.timestamp.timeIntervalSince1970 * 1_000),
                "provider": "corelocation",
            ]
            if let id = request.id {
                payload["id"] = id
            }
            dispatch(event: request.event, payload: payload)
        }
    }

    func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        let locationError = error as? CLError
        let message = locationError?.code == .denied
            ? "El permiso o los servicios de ubicación no están disponibles."
            : "No fue posible obtener la ubicación actual."
        failPositions(message)
    }

    private func failPositions(_ message: String) {
        timeout?.cancel()
        timeout = nil
        locationRequestInFlight = false

        let requests = positionRequests
        positionRequests.removeAll()
        requests.forEach { dispatchLocationError($0, message: message) }
    }

    private func dispatchLocationError(_ request: PositionRequest, message: String) {
        var payload: [String: Any] = [
            "success": false,
            "error": message,
        ]
        if let id = request.id {
            payload["id"] = id
        }
        dispatch(event: request.event, payload: payload)
    }

    private func dispatchPermissionState(event: String, id: String?, requestResult: Bool) {
        let status = manager.authorizationStatus
        let authorized = status == .authorizedAlways || status == .authorizedWhenInUse
        let fineAuthorized = authorized && manager.accuracyAuthorization == .fullAccuracy
        let fallback = permissionFallback(status: status, requestResult: requestResult)

        var payload: [String: Any] = [
            "location": authorized ? "granted" : fallback,
            "coarseLocation": authorized ? "granted" : fallback,
            "fineLocation": fineAuthorized ? "granted" : fallback,
        ]
        if let id {
            payload["id"] = id
        }
        dispatch(event: event, payload: payload)
    }

    private func permissionFallback(
        status: CLAuthorizationStatus,
        requestResult: Bool
    ) -> String {
        switch status {
        case .notDetermined:
            return "not_determined"
        case .denied:
            return requestResult ? "permanently_denied" : "denied"
        case .restricted:
            return "denied"
        case .authorizedAlways, .authorizedWhenInUse:
            return "denied"
        @unknown default:
            return "denied"
        }
    }

    private func dispatch(event: String, payload: [String: Any]) {
        dispatchPrecondition(condition: .onQueue(.main))
        LaravelBridge.shared.send?(event, payload)
    }
}
