package dev.momotombo.elver.plugins.geolocation

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject
import kotlin.math.abs

object ElverGeolocationFunctions {
    private const val LOCATION_EVENT = "Native\\Mobile\\Events\\Geolocation\\LocationReceived"
    private const val PERMISSION_STATUS_EVENT =
        "Native\\Mobile\\Events\\Geolocation\\PermissionStatusReceived"
    private const val PERMISSION_REQUEST_EVENT =
        "Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult"

    class GetCurrentPosition(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            activity.runOnUiThread {
                GeolocationCoordinator.install(activity).getCurrentPosition(
                    PositionRequest(
                        id = parameters["id"] as? String,
                        event = parameters["event"] as? String ?: LOCATION_EVENT,
                        fineAccuracy = parameters["fineAccuracy"] as? Boolean ?: false,
                    ),
                )
            }

            return BridgeResponse.success(emptyMap<String, Any>())
        }
    }

    class CheckPermissions(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            activity.runOnUiThread {
                val event = parameters["event"] as? String ?: PERMISSION_STATUS_EVENT
                val id = parameters["id"] as? String
                GeolocationCoordinator.install(activity).checkPermissions(event, id)
            }

            return BridgeResponse.success(emptyMap<String, Any>())
        }
    }

    class RequestPermissions(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            activity.runOnUiThread {
                GeolocationCoordinator.install(activity).requestPermissions(
                    PermissionRequest(
                        id = parameters["id"] as? String,
                        event = parameters["event"] as? String ?: PERMISSION_REQUEST_EVENT,
                    ),
                )
            }

            return BridgeResponse.success(emptyMap<String, Any>())
        }
    }
}

data class PositionRequest(
    val id: String?,
    val event: String,
    val fineAccuracy: Boolean,
)

data class PermissionRequest(
    val id: String?,
    val event: String,
)

class GeolocationCoordinator : Fragment(), LocationListener {
    private val handler = Handler(Looper.getMainLooper())
    private val positionRequests = mutableListOf<PositionRequest>()
    private val permissionRequests = mutableListOf<PermissionRequest>()
    private var permissionRequestInFlight = false
    private var locationRequestInFlight = false
    private var fineNetworkFallbackUsed = false
    private val activeProviders = mutableSetOf<String>()

    private val permissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
            permissionRequestInFlight = false

            permissionRequests.toList().forEach { request ->
                dispatchPermissionState(request.event, request.id, requestResult = true)
            }
            permissionRequests.clear()

            if (hasAnyLocationPermission()) {
                startLocationRequest()
            } else {
                failPositions("El permiso de ubicación fue denegado.")
            }
        }

    private val timeout = Runnable { handleLocationTimeout() }

    private val preferences by lazy {
        val current = requireContext().getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
        val legacy = requireContext().getSharedPreferences(LEGACY_PREFERENCES, Context.MODE_PRIVATE)

        if (!current.contains(KEY_PERMISSION_REQUESTED) && legacy.contains(KEY_PERMISSION_REQUESTED)) {
            current.edit()
                .putBoolean(KEY_PERMISSION_REQUESTED, legacy.getBoolean(KEY_PERMISSION_REQUESTED, false))
                .apply()
        }

        current
    }

    private val locationManager by lazy {
        requireContext().getSystemService(Context.LOCATION_SERVICE) as LocationManager
    }

    fun getCurrentPosition(request: PositionRequest) {
        if (!hasAnyLocationPermission()) {
            if (hasRequestedPermission()) {
                dispatchLocationError(request, "El permiso de ubicación está denegado.")
                return
            }

            positionRequests += request
            launchPermissionRequest()
            return
        }

        if (positionRequests.isEmpty()) {
            fineNetworkFallbackUsed = false
        }
        positionRequests += request
        startLocationRequest()
    }

    fun checkPermissions(event: String, id: String?) {
        dispatchPermissionState(event, id, requestResult = false)
    }

    fun requestPermissions(request: PermissionRequest) {
        if (hasAnyLocationPermission()) {
            dispatchPermissionState(request.event, request.id, requestResult = true)
            return
        }

        if (isPermanentlyDenied()) {
            dispatchPermissionState(request.event, request.id, requestResult = true)
            return
        }

        permissionRequests += request
        launchPermissionRequest()
    }

    private fun launchPermissionRequest() {
        if (permissionRequestInFlight) {
            return
        }

        permissionRequestInFlight = true
        preferences.edit().putBoolean(KEY_PERMISSION_REQUESTED, true).apply()
        permissionLauncher.launch(
            arrayOf(
                Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.ACCESS_COARSE_LOCATION,
            ),
        )
    }

    private fun startLocationRequest() {
        if (positionRequests.isEmpty() || locationRequestInFlight) {
            return
        }

        val wantsFineAccuracy = positionRequests.any(PositionRequest::fineAccuracy)
        val providers = chooseProviders(wantsFineAccuracy)

        if (providers.isEmpty()) {
            failPositions("No hay un proveedor de ubicación compatible con los permisos concedidos.")
            return
        }

        if (wantsFineAccuracy && LocationManager.NETWORK_PROVIDER in providers
            && LocationManager.GPS_PROVIDER !in providers) {
            fineNetworkFallbackUsed = true
        }

        val cachedLocation = providers
            .mapNotNull(::lastKnownLocation)
            .filter { isRecent(it) && (!wantsFineAccuracy || fineNetworkFallbackUsed || isAcceptablyAccurate(it)) }
            .maxByOrNull { it.time }

        if (cachedLocation != null) {
            dispatchLocation(cachedLocation)
            return
        }

        try {
            activeProviders.clear()
            locationRequestInFlight = true
            providers.forEach { provider ->
                activeProviders += provider
                locationManager.requestLocationUpdates(
                    provider,
                    0L,
                    0f,
                    this,
                    Looper.getMainLooper(),
                )
            }
            handler.postDelayed(timeout, LOCATION_TIMEOUT_MS)
        } catch (_: SecurityException) {
            failPositions("El permiso de ubicación no está disponible.")
        } catch (_: IllegalArgumentException) {
            failPositions("No hay un proveedor de ubicación disponible.")
        }
    }

    private fun chooseProviders(fineAccuracy: Boolean): List<String> {
        val hasFinePermission = hasPermission(Manifest.permission.ACCESS_FINE_LOCATION)
        val gpsEnabled = hasFinePermission && isProviderEnabled(LocationManager.GPS_PROVIDER)
        val networkEnabled = isProviderEnabled(LocationManager.NETWORK_PROVIDER)

        if (fineAccuracy) {
            return when {
                gpsEnabled && !fineNetworkFallbackUsed -> listOf(LocationManager.GPS_PROVIDER)
                networkEnabled -> listOf(LocationManager.NETWORK_PROVIDER)
                gpsEnabled -> listOf(LocationManager.GPS_PROVIDER)
                else -> emptyList()
            }
        }

        return buildList {
            if (networkEnabled) {
                add(LocationManager.NETWORK_PROVIDER)
            }
            if (gpsEnabled) {
                add(LocationManager.GPS_PROVIDER)
            }
        }
    }

    private fun isProviderEnabled(provider: String): Boolean =
        try {
            locationManager.isProviderEnabled(provider)
        } catch (_: Exception) {
            false
        }

    override fun onLocationChanged(location: Location) {
        if (positionRequests.any(PositionRequest::fineAccuracy)
            && !fineNetworkFallbackUsed
            && !isAcceptablyAccurate(location)) {
            return
        }

        dispatchLocation(location)
    }

    private fun dispatchLocation(location: Location) {
        handler.removeCallbacks(timeout)
        stopLocationUpdates()
        locationRequestInFlight = false
        fineNetworkFallbackUsed = false
        activeProviders.clear()

        positionRequests.toList().forEach { request ->
            val payload = JSONObject().apply {
                put("success", true)
                put("latitude", location.latitude)
                put("longitude", location.longitude)
                put("accuracy", location.accuracy.toDouble())
                put("timestamp", location.time)
                put("provider", location.provider ?: "android")
                request.id?.let { put("id", it) }
            }
            dispatch(request.event, payload)
        }
        positionRequests.clear()
    }

    override fun onProviderDisabled(provider: String) {
        if (activeProviders.remove(provider) && activeProviders.isEmpty()) {
            failPositions("El proveedor de ubicación fue desactivado.")
        }
    }

    @Deprecated("Required by LocationListener on older Android versions")
    override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) = Unit

    private fun failPositions(message: String) {
        handler.removeCallbacks(timeout)
        if (locationRequestInFlight) {
            stopLocationUpdates()
        }
        locationRequestInFlight = false
        fineNetworkFallbackUsed = false
        activeProviders.clear()

        positionRequests.toList().forEach { dispatchLocationError(it, message) }
        positionRequests.clear()
    }

    private fun dispatchLocationError(request: PositionRequest, message: String) {
        val payload = JSONObject().apply {
            put("success", false)
            put("error", message)
            request.id?.let { put("id", it) }
        }
        dispatch(request.event, payload)
    }

    private fun dispatchPermissionState(event: String, id: String?, requestResult: Boolean) {
        val fineGranted = hasPermission(Manifest.permission.ACCESS_FINE_LOCATION)
        val coarseGranted = hasPermission(Manifest.permission.ACCESS_COARSE_LOCATION)
        val fallback = permissionFallback(requestResult)

        val payload = JSONObject().apply {
            put("location", if (fineGranted || coarseGranted) GRANTED else fallback)
            put("coarseLocation", if (coarseGranted) GRANTED else fallback)
            put("fineLocation", if (fineGranted) GRANTED else fallback)
            id?.let { put("id", it) }
        }
        dispatch(event, payload)
    }

    private fun permissionFallback(requestResult: Boolean): String {
        if (!hasRequestedPermission()) {
            return NOT_DETERMINED
        }

        if (requestResult && isPermanentlyDenied()) {
            return PERMANENTLY_DENIED
        }

        return DENIED
    }

    private fun isPermanentlyDenied(): Boolean =
        hasRequestedPermission() &&
            !hasAnyLocationPermission() &&
            !shouldShowRequestPermissionRationale(Manifest.permission.ACCESS_FINE_LOCATION) &&
            !shouldShowRequestPermissionRationale(Manifest.permission.ACCESS_COARSE_LOCATION)

    private fun hasRequestedPermission(): Boolean =
        preferences.getBoolean(KEY_PERMISSION_REQUESTED, false)

    private fun hasAnyLocationPermission(): Boolean =
        hasPermission(Manifest.permission.ACCESS_FINE_LOCATION) ||
            hasPermission(Manifest.permission.ACCESS_COARSE_LOCATION)

    private fun hasPermission(permission: String): Boolean =
        ContextCompat.checkSelfPermission(requireContext(), permission) ==
            PackageManager.PERMISSION_GRANTED

    private fun dispatch(event: String, payload: JSONObject) {
        NativeActionCoordinator.dispatchEvent(requireActivity(), event, payload.toString())
    }

    private fun lastKnownLocation(provider: String): Location? =
        try {
            locationManager.getLastKnownLocation(provider)
        } catch (_: SecurityException) {
            null
        }

    private fun isRecent(location: Location): Boolean =
        abs(System.currentTimeMillis() - location.time) <= LOCATION_CACHE_MAX_AGE_MS

    private fun isAcceptablyAccurate(location: Location): Boolean =
        location.hasAccuracy() && location.accuracy <= FINE_ACCURACY_MAX_METERS

    private fun handleLocationTimeout() {
        if (positionRequests.any(PositionRequest::fineAccuracy)
            && !fineNetworkFallbackUsed
            && isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
            fineNetworkFallbackUsed = true
            stopLocationUpdates()
            locationRequestInFlight = false
            activeProviders.clear()
            startLocationRequest()
            return
        }

        failPositions("No fue posible obtener la ubicación a tiempo.")
    }

    private fun stopLocationUpdates() {
        try {
            locationManager.removeUpdates(this)
        } catch (_: SecurityException) {
            // Permission can be revoked while a request is in flight.
        }
    }

    override fun onDestroy() {
        handler.removeCallbacks(timeout)
        if (locationRequestInFlight) {
            stopLocationUpdates()
        }
        super.onDestroy()
    }

    companion object {
        private const val FRAGMENT_TAG = "ElverGeolocationCoordinator"
        private const val LOCATION_TIMEOUT_MS = 15_000L
        private const val LOCATION_CACHE_MAX_AGE_MS = 120_000L
        private const val FINE_ACCURACY_MAX_METERS = 100f
        private const val KEY_PERMISSION_REQUESTED = "permission_requested"
        private const val PREFERENCES = "elver_geolocation"
        private const val LEGACY_PREFERENCES = "agroclima_geolocation"
        private const val GRANTED = "granted"
        private const val DENIED = "denied"
        private const val NOT_DETERMINED = "not_determined"
        private const val PERMANENTLY_DENIED = "permanently_denied"

        fun install(activity: FragmentActivity): GeolocationCoordinator =
            activity.supportFragmentManager.findFragmentByTag(FRAGMENT_TAG) as? GeolocationCoordinator
                ?: GeolocationCoordinator().also { fragment ->
                    activity.supportFragmentManager.beginTransaction()
                        .add(fragment, FRAGMENT_TAG)
                        .commitNowAllowingStateLoss()
                }
    }
}
