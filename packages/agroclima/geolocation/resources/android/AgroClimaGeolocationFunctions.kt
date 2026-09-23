package com.agroclima.plugins.geolocation

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

object AgroClimaGeolocationFunctions {
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
    private var activeProvider: String? = null

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

    private val timeout = Runnable {
        failPositions("No fue posible obtener la ubicación a tiempo.")
    }

    private val preferences by lazy {
        requireContext().getSharedPreferences("agroclima_geolocation", Context.MODE_PRIVATE)
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
        val provider = chooseProvider(wantsFineAccuracy)

        if (provider == null) {
            failPositions("No hay un proveedor de ubicación compatible con los permisos concedidos.")
            return
        }

        try {
            activeProvider = provider
            locationRequestInFlight = true
            locationManager.requestLocationUpdates(
                provider,
                0L,
                0f,
                this,
                Looper.getMainLooper(),
            )
            handler.postDelayed(timeout, LOCATION_TIMEOUT_MS)
        } catch (_: SecurityException) {
            failPositions("El permiso de ubicación no está disponible.")
        } catch (_: IllegalArgumentException) {
            failPositions("No hay un proveedor de ubicación disponible.")
        }
    }

    private fun chooseProvider(fineAccuracy: Boolean): String? {
        val hasFinePermission = hasPermission(Manifest.permission.ACCESS_FINE_LOCATION)

        if (fineAccuracy && hasFinePermission && isProviderEnabled(LocationManager.GPS_PROVIDER)) {
            return LocationManager.GPS_PROVIDER
        }

        if (isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
            return LocationManager.NETWORK_PROVIDER
        }

        if (hasFinePermission && isProviderEnabled(LocationManager.GPS_PROVIDER)) {
            return LocationManager.GPS_PROVIDER
        }

        return null
    }

    private fun isProviderEnabled(provider: String): Boolean =
        try {
            locationManager.isProviderEnabled(provider)
        } catch (_: Exception) {
            false
        }

    override fun onLocationChanged(location: Location) {
        handler.removeCallbacks(timeout)
        stopLocationUpdates()
        locationRequestInFlight = false
        activeProvider = null

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
        if (provider == activeProvider) {
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
        activeProvider = null

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
        private const val FRAGMENT_TAG = "AgroClimaGeolocationCoordinator"
        private const val LOCATION_TIMEOUT_MS = 15_000L
        private const val KEY_PERMISSION_REQUESTED = "permission_requested"
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
