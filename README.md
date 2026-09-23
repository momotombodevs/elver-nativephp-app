<p align="center">
  <img src="./resources/images/elver-app-icon.png" alt="Elver app icon" width="168">
</p>

<h1 align="center">Elver</h1>

<p align="center">
  <strong>Weather for the places that matter, even when connectivity fails.</strong><br>
  A local-first weather app for iOS and Android, built with Laravel and NativePHP Mobile.
</p>

<p align="center">
  <a href="#core-capabilities">Capabilities</a> ·
  <a href="#architecture">Architecture</a> ·
  <a href="#technology">Technology</a> ·
  <a href="#testing">Testing</a>
</p>

## Overview

Elver brings forecasts, native charts, saved locations, and climate alerts into one mobile experience. Recent weather data stays on the device, giving users useful context even when the network or weather provider is temporarily unavailable.

## Core capabilities

| Capability | What it provides |
| --- | --- |
| Weather summary | Current conditions and a forecast for the next 24 hours. |
| Chart explorer | Native line, area, and bar charts for temperature, humidity, precipitation, and wind. |
| Saved locations | Foreground geolocation and local management of multiple places on iOS and Android. |
| Climate alerts | Threshold-based rules with local notifications and background checks. |
| Offline resilience | SQLite snapshots keep the latest available forecast accessible without a connection. |

## Local-first flow

1. Elver loads the latest stored forecast for the selected location.
2. A queued refresh requests current data from Open-Meteo.
3. Successful responses replace the local snapshot and update the native UI.
4. Failed refreshes leave cached data visible and clearly identify it as stored information.

## Architecture

| Layer | Responsibility |
| --- | --- |
| Native UI | `NativeComponent` screens and EDGE views rendered with SwiftUI and Jetpack Compose. |
| Domain | Typed weather data, metrics, thresholds, and alert evaluation rules. |
| Application services | Forecast orchestration, chart preparation, refresh queues, and background scheduling. |
| Persistence | Locations, weather snapshots, and alert state stored in SQLite. |
| Native bridges | Local plugins for foreground geolocation and background climate notifications. |

## Technology

| Area | Implementation |
| --- | --- |
| Application | Laravel 13 and PHP 8.4+ |
| Mobile runtime | NativePHP Mobile v4 |
| Native interface | SwiftUI on iOS and Jetpack Compose on Android |
| Charts | `donmanueldev/nativephp-charts` |
| Weather data | Open-Meteo |
| Persistence | SQLite |
| Testing | Pest |

## Project structure

```text
app/Domain/AgroClima/       Weather and alert domain contracts
app/NativeComponents/      Native screen state and interactions
app/Services/AgroClima/    Forecast, chart, and alert orchestration
packages/agroclima/         Local geolocation and notification plugins
resources/views/native/    EDGE views rendered as native UI
tests/                      Domain, service, job, and native UI coverage
```


---

<p align="center">
  Crafted with passion by <a href="https://momotombo.dev/"><strong>Momotombo Devs</strong></a>
  <a href="https://momotombo.dev/"><img src="./public/images/momotombo.svg" width="28" height="14" alt=""></a>
</p>
