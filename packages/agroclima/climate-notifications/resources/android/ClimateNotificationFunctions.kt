package com.agroclima.plugins.climatenotifications

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.Locale
import java.util.concurrent.TimeUnit

object ClimateNotificationFunctions {
    private const val PERMISSION_EVENT = "App\\Events\\ClimateNotificationPermissionResult"

    class CheckPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            activity.runOnUiThread {
                NotificationPermissionCoordinator.install(activity).check(
                    parameters["event"] as? String ?: PERMISSION_EVENT,
                    parameters["id"] as? String,
                )
            }

            return BridgeResponse.success(emptyMap())
        }
    }

    class RequestPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            activity.runOnUiThread {
                NotificationPermissionCoordinator.install(activity).request(
                    PermissionRequest(
                        event = parameters["event"] as? String ?: PERMISSION_EVENT,
                        id = parameters["id"] as? String,
                    ),
                )
            }

            return BridgeResponse.success(emptyMap())
        }
    }

    class SyncSchedule(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            ClimateAlertSchedule.sync(context, JSONObject(parameters).toString())

            return BridgeResponse.success(emptyMap())
        }
    }
}

private data class PermissionRequest(val event: String, val id: String?)

private class NotificationPermissionCoordinator : Fragment() {
    private val pendingRequests = mutableListOf<PermissionRequest>()
    private var permissionRequestInFlight = false

    private val notificationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) {
            permissionRequestInFlight = false
            val allowed = notificationsAllowed(requireContext())
            pendingRequests.toList().forEach { request ->
                dispatch(request.event, request.id, allowed)
            }
            pendingRequests.clear()
        }

    companion object {
        private const val FRAGMENT_TAG = "agroclima.notification-permission"

        fun install(activity: FragmentActivity): NotificationPermissionCoordinator {
            val manager = activity.supportFragmentManager
            val existing = manager.findFragmentByTag(FRAGMENT_TAG)

            if (existing is NotificationPermissionCoordinator) {
                return existing
            }

            return NotificationPermissionCoordinator().also { fragment ->
                manager.beginTransaction()
                    .add(fragment, FRAGMENT_TAG)
                    // A tab switch can arrive after Android saved the
                    // activity state. This coordinator is headless, so
                    // allowing state loss avoids killing the app while the
                    // next screen is being mounted; it will be found or
                    // installed again on the next request.
                    .commitNowAllowingStateLoss()
            }
        }
    }

    fun check(event: String, id: String?) {
        dispatch(event, id, notificationsAllowed(requireContext()))
    }

    fun request(request: PermissionRequest) {
        val context = requireContext()

        if (notificationsAllowed(context) || Build.VERSION.SDK_INT < 33) {
            dispatch(request.event, request.id, notificationsAllowed(context))
            return
        }

        pendingRequests.add(request)
        if (permissionRequestInFlight) {
            return
        }

        permissionRequestInFlight = true
        notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
    }

    private fun dispatch(event: String, id: String?, allowed: Boolean) {
        val payload = JSONObject().put("granted", allowed)
        id?.let { payload.put("id", it) }
        NativeActionCoordinator.dispatchEvent(requireActivity(), event, payload.toString())
    }

    private fun notificationsAllowed(context: Context): Boolean {
        val runtimePermissionGranted = Build.VERSION.SDK_INT < 33 ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) ==
            PackageManager.PERMISSION_GRANTED
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val channelEnabled = Build.VERSION.SDK_INT < 26 || manager
            .getNotificationChannel("climate_alerts")
            ?.importance != NotificationManager.IMPORTANCE_NONE

        return runtimePermissionGranted && channelEnabled && NotificationManagerCompat.from(context).areNotificationsEnabled()
    }
}

private object ClimateAlertSchedule {
    private const val PREFERENCES = "agroclima_climate_notifications"
    private const val SCHEDULE_KEY = "schedule"
    private const val ALERT_STATES_KEY = "alert_states"
    private const val WORK_NAME = "agroclima-climate-alert-refresh"
    private const val CHANNEL_ID = "climate_alerts"
    private const val CHANNEL_NAME = "Alertas climáticas"
    private const val REFRESH_INTERVAL_HOURS = 1L

    fun sync(context: Context, scheduleJson: String) {
        val preferences = context.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
        val schedule = JSONObject(scheduleJson)
        val locations = schedule.optJSONArray("locations") ?: JSONArray()
        val alerts = flattenAlerts(locations)
        val states = reconcileStates(preferences.getString(ALERT_STATES_KEY, "{}"), alerts)

        preferences.edit()
            .putString(SCHEDULE_KEY, schedule.toString())
            .putString(ALERT_STATES_KEY, states.toString())
            .apply()

        if (alerts.length() == 0 || !notificationsAllowed(context)) {
            WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
            return
        }

        createNotificationChannel(context)
        val request = PeriodicWorkRequestBuilder<ClimateAlertWorker>(
            REFRESH_INTERVAL_HOURS,
            TimeUnit.HOURS,
        )
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build(),
            )
            .build()

        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            WORK_NAME,
            ExistingPeriodicWorkPolicy.UPDATE,
            request,
        )
    }

    fun cancel(context: Context) {
        WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
    }

    fun notificationAllowed(context: Context): Boolean = notificationsAllowed(context)

    fun schedule(context: Context): JSONObject? = context
        .getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
        .getString(SCHEDULE_KEY, null)
        ?.let(::JSONObject)

    fun alertStates(context: Context): JSONObject = JSONObject(
        context.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
            .getString(ALERT_STATES_KEY, "{}").orEmpty(),
    )

    fun saveAlertStates(context: Context, states: JSONObject) {
        context.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
            .edit()
            .putString(ALERT_STATES_KEY, filterStates(context, states).toString())
            .apply()
    }

    fun isActive(context: Context, alertId: String, signature: String): Boolean {
        val locations = schedule(context)?.optJSONArray("locations") ?: return false

        for (locationIndex in 0 until locations.length()) {
            val alerts = locations.optJSONObject(locationIndex)?.optJSONArray("alerts") ?: continue
            for (alertIndex in 0 until alerts.length()) {
                val alert = alerts.optJSONObject(alertIndex) ?: continue
                if (alert.optString("id") == alertId && alert.optString("signature") == signature) {
                    return true
                }
            }
        }

        return false
    }

    private fun filterStates(context: Context, states: JSONObject): JSONObject {
        val alerts = flattenAlerts(schedule(context)?.optJSONArray("locations") ?: JSONArray())
        val activeStates = JSONObject()

        for (index in 0 until alerts.length()) {
            val alert = alerts.optJSONObject(index) ?: continue
            val id = alert.optString("id")
            val state = states.optJSONObject(id) ?: continue
            if (state.optString("signature") == alert.optString("signature")) {
                activeStates.put(id, state)
            }
        }

        return activeStates
    }

    private fun flattenAlerts(locations: JSONArray): JSONArray {
        val alerts = JSONArray()

        for (index in 0 until locations.length()) {
            val location = locations.optJSONObject(index) ?: continue
            val locationAlerts = location.optJSONArray("alerts") ?: continue

            for (alertIndex in 0 until locationAlerts.length()) {
                val alert = locationAlerts.optJSONObject(alertIndex) ?: continue
                alerts.put(alert.put("locationId", location.optString("id")))
            }
        }

        return alerts
    }

    private fun reconcileStates(previousJson: String?, alerts: JSONArray): JSONObject {
        val previous = runCatching { JSONObject(previousJson ?: "{}") }.getOrDefault(JSONObject())
        val next = JSONObject()

        for (index in 0 until alerts.length()) {
            val alert = alerts.optJSONObject(index) ?: continue
            val id = alert.optString("id")
            val signature = alert.optString("signature")
            val initialState = alert.optString("lastState").takeUnless { it == "null" }
            val initialTimestamp = alert.optLong("lastEvaluatedAt", 0L)
            val saved = previous.optJSONObject(id)

            val state = if (saved == null || saved.optString("signature") != signature) {
                statePayload(signature, initialState, initialTimestamp)
            } else if (initialTimestamp > saved.optLong("evaluatedAt", 0L)) {
                statePayload(signature, initialState, initialTimestamp)
            } else {
                saved
            }

            next.put(id, state)
        }

        return next
    }

    private fun statePayload(signature: String, state: String?, timestamp: Long): JSONObject = JSONObject()
        .put("signature", signature)
        .put("state", state)
        .put("evaluatedAt", timestamp)

    private fun notificationsAllowed(context: Context): Boolean {
        val permissionGranted = Build.VERSION.SDK_INT < 33 ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) ==
            PackageManager.PERMISSION_GRANTED
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val channelEnabled = Build.VERSION.SDK_INT < 26 || manager
            .getNotificationChannel(CHANNEL_ID)
            ?.importance != NotificationManager.IMPORTANCE_NONE

        return permissionGranted && channelEnabled && NotificationManagerCompat.from(context).areNotificationsEnabled()
    }

    private fun createNotificationChannel(context: Context) {
        if (Build.VERSION.SDK_INT < 26) {
            return
        }

        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        manager.createNotificationChannel(
            NotificationChannel(CHANNEL_ID, CHANNEL_NAME, NotificationManager.IMPORTANCE_DEFAULT),
        )
    }
}

class ClimateAlertWorker(
    context: Context,
    parameters: WorkerParameters,
) : Worker(context, parameters) {
    override fun doWork(): Result {
        if (!ClimateAlertSchedule.notificationAllowed(applicationContext)) {
            ClimateAlertSchedule.cancel(applicationContext)
            return Result.success()
        }

        val schedule = ClimateAlertSchedule.schedule(applicationContext) ?: return Result.success()
        val locations = schedule.optJSONArray("locations") ?: return Result.success()
        val forecastUrl = secureForecastUrl(schedule.optString("forecastUrl")) ?: return Result.failure()
        val states = ClimateAlertSchedule.alertStates(applicationContext)
        var hadRequestFailure = false

        for (index in 0 until locations.length()) {
            if (isStopped) {
                return Result.success()
            }

            val location = locations.optJSONObject(index) ?: continue
            val current = fetchCurrentWeather(
                forecastUrl,
                location.optDouble("latitude", Double.NaN),
                location.optDouble("longitude", Double.NaN),
            )

            if (current == null) {
                hadRequestFailure = true
                continue
            }

            val alerts = location.optJSONArray("alerts") ?: continue
            val now = System.currentTimeMillis()

            for (alertIndex in 0 until alerts.length()) {
                if (isStopped) {
                    return Result.success()
                }

                val alert = alerts.optJSONObject(alertIndex) ?: continue
                val id = alert.optString("id")
                val signature = alert.optString("signature")
                val metric = alert.optString("metric")
                val value = current[metric]
                val state = alertState(value, alert.optString("operator"), alert.optDouble("threshold", Double.NaN))
                val saved = states.optJSONObject(id)
                val previousState = if (saved == null || saved.optString("signature") != signature) {
                    alert.optString("lastState").takeUnless { it == "null" }
                } else {
                    saved.optString("state").takeUnless { it == "null" }
                }

                if (state == "exceeded" && previousState != "exceeded" &&
                    ClimateAlertSchedule.isActive(applicationContext, id, signature)) {
                    showNotification(applicationContext, location.optString("name"), alert)
                }

                states.put(
                    id,
                    JSONObject()
                        .put("signature", signature)
                        .put("state", state)
                        .put("evaluatedAt", now),
                )
            }
        }

        ClimateAlertSchedule.saveAlertStates(applicationContext, states)

        return if (hadRequestFailure) Result.retry() else Result.success()
    }

    private fun fetchCurrentWeather(endpoint: String, latitude: Double, longitude: Double): Map<String, Double?>? {
        if (!latitude.isFinite() || !longitude.isFinite() || latitude !in -90.0..90.0 || longitude !in -180.0..180.0) {
            return null
        }

        val uri = Uri.parse(endpoint).buildUpon()
            .appendQueryParameter("latitude", latitude.toString())
            .appendQueryParameter("longitude", longitude.toString())
            .appendQueryParameter("current", METRICS.joinToString(","))
            .appendQueryParameter("timezone", "auto")
            .appendQueryParameter("timeformat", "unixtime")
            .build()
        val connection = URL(uri.toString()).openConnection() as HttpURLConnection

        return try {
            connection.connectTimeout = 5_000
            connection.readTimeout = 8_000
            connection.requestMethod = "GET"

            if (connection.responseCode !in 200..299) {
                return null
            }

            val current = connection.inputStream.bufferedReader().use { reader ->
                JSONObject(reader.readText()).optJSONObject("current")
            } ?: return null

            METRICS.associateWith { metric ->
                val value = current.optDouble(metric, Double.NaN)
                value.takeIf { it.isFinite() }
            }
        } catch (_: Exception) {
            null
        } finally {
            connection.disconnect()
        }
    }

    private fun alertState(value: Double?, operator: String, threshold: Double): String {
        if (value == null || !threshold.isFinite()) {
            return "no_data"
        }

        val exceeded = when (operator) {
            "above" -> value > threshold
            "below" -> value < threshold
            else -> false
        }

        return if (exceeded) "exceeded" else "normal"
    }

    private fun showNotification(context: Context, locationName: String, alert: JSONObject) {
        if (!ClimateAlertSchedule.notificationAllowed(context)) {
            return
        }

        val locationIntent = context.packageManager.getLaunchIntentForPackage(context.packageName)
            ?.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        val contentIntent = locationIntent?.let {
            PendingIntent.getActivity(
                context,
                alert.optString("id").hashCode(),
                it,
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
            )
        }
        val operator = if (alert.optString("operator") == "above") "superó" else "bajó de"
        val threshold = String.format(
            Locale.forLanguageTag("es-NI"),
            "%.1f",
            alert.optDouble("threshold"),
        )
        val message = "${alert.optString("label")} $operator $threshold ${alert.optString("unit")} en $locationName."
        val notification = NotificationCompat.Builder(context, "climate_alerts")
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setContentTitle("Alerta climática")
            .setContentText(message)
            .setStyle(NotificationCompat.BigTextStyle().bigText(message))
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)

        contentIntent?.let(notification::setContentIntent)
        try {
            NotificationManagerCompat.from(context).notify(alert.optString("id").hashCode(), notification.build())
        } catch (_: SecurityException) {
            // The user can revoke permission between the check and notification delivery.
        }
    }

    private fun secureForecastUrl(value: String): String? {
        val candidate = value.ifBlank { "https://api.open-meteo.com/v1/forecast" }
        val uri = runCatching { Uri.parse(candidate) }.getOrNull() ?: return null

        return candidate.takeIf { uri.scheme == "https" && !uri.host.isNullOrBlank() }
    }

    companion object {
        private val METRICS = listOf(
            "temperature_2m",
            "relative_humidity_2m",
            "precipitation",
            "wind_speed_10m",
        )
    }
}
