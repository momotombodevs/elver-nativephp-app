const currentYear = new Date().getFullYear();
const locale = document.documentElement.lang.startsWith('en') ? 'en' : 'es';
const localeConfig = {
    es: {
        dateLocale: 'es-NI',
        hour12: false,
        updated: 'Actualizado',
        updatedNow: 'Actualizado ahora',
        offline: 'Ejemplo sin conexión',
        invalid: 'Ejemplo sin conexión',
        now: 'Ahora',
        nextDay: '+24 h',
        variable: 'Condición variable',
        weather: new Map([
            [0, 'Despejado'], [1, 'Mayormente despejado'], [2, 'Parcialmente nublado'], [3, 'Nublado'],
            [45, 'Neblina'], [48, 'Neblina'], [51, 'Llovizna ligera'], [53, 'Llovizna'], [55, 'Llovizna intensa'],
            [61, 'Lluvia ligera'], [63, 'Lluvia'], [65, 'Lluvia intensa'], [80, 'Chubascos ligeros'],
            [81, 'Chubascos'], [82, 'Chubascos intensos'], [95, 'Tormenta'], [96, 'Tormenta con granizo'], [99, 'Tormenta intensa'],
        ]),
    },
    en: {
        dateLocale: 'en-US',
        hour12: true,
        updated: 'Updated',
        updatedNow: 'Updated just now',
        offline: 'Example offline',
        invalid: 'Example unavailable',
        now: 'Now',
        nextDay: '+24 h',
        variable: 'Variable conditions',
        weather: new Map([
            [0, 'Clear'], [1, 'Mostly clear'], [2, 'Partly cloudy'], [3, 'Overcast'],
            [45, 'Fog'], [48, 'Fog'], [51, 'Light drizzle'], [53, 'Drizzle'], [55, 'Heavy drizzle'],
            [61, 'Light rain'], [63, 'Rain'], [65, 'Heavy rain'], [80, 'Light showers'],
            [81, 'Showers'], [82, 'Heavy showers'], [95, 'Thunderstorm'], [96, 'Thunderstorm with hail'], [99, 'Severe thunderstorm'],
        ]),
    },
};
const copy = localeConfig[locale];
const landingCoordinates = { latitude: 12.1364, longitude: -86.2514, place: 'Managua' };
const weatherApiUrl = new URL('https://api.open-meteo.com/v1/forecast');

weatherApiUrl.search = new URLSearchParams({
    latitude: landingCoordinates.latitude.toString(),
    longitude: landingCoordinates.longitude.toString(),
    current: 'temperature_2m,weather_code',
    hourly: 'temperature_2m,precipitation_probability,weather_code',
    forecast_days: '2',
    timezone: 'America/Managua',
}).toString();

const setText = (selector, value) => {
    document.querySelectorAll(selector).forEach((element) => {
        element.textContent = value;
    });
};

const formatTemperature = (value) => Number.isFinite(Number(value))
    ? `${Math.round(Number(value))}°`
    : '—';

const formatHour = (isoTime) => new Intl.DateTimeFormat(copy.dateLocale, {
    hour: 'numeric',
    hour12: copy.hour12,
    timeZone: 'America/Managua',
}).format(new Date(isoTime));

const describeWeather = (code) => copy.weather.get(Number(code)) ?? copy.variable;

const getClosestHourlyIndex = (times, currentTime) => {
    const currentTimestamp = new Date(currentTime).getTime();

    return times.reduce((closestIndex, time, index) => {
        const closestDistance = Math.abs(new Date(times[closestIndex]).getTime() - currentTimestamp);
        const distance = Math.abs(new Date(time).getTime() - currentTimestamp);

        return distance < closestDistance ? index : closestIndex;
    }, 0);
};

const hasForecastShape = (weather) => weather?.current
    && Array.isArray(weather?.hourly?.time)
    && Array.isArray(weather?.hourly?.temperature_2m)
    && Array.isArray(weather?.hourly?.weather_code)
    && weather.hourly.time.length > 0;

const updateForecastPoint = (point, hourly, baseIndex) => {
    const offset = Number(point.dataset.hourIndex);
    const hourlyIndex = Math.min(baseIndex + offset, hourly.time.length - 1);
    const temperature = hourly.temperature_2m[hourlyIndex];
    const description = describeWeather(hourly.weather_code[hourlyIndex]);
    const timeLabel = offset === 0
        ? copy.now
        : offset === 24
            ? copy.nextDay
            : formatHour(hourly.time[hourlyIndex]);
    const probability = Number(hourly.precipitation_probability?.[hourlyIndex] ?? 0);

    point.querySelector('span').textContent = timeLabel;
    point.querySelector('strong').textContent = formatTemperature(temperature);
    point.querySelector('small').textContent = description;

    point.querySelectorAll('.rain-bars i').forEach((bar, index) => {
        const height = Math.max(25, Math.min(100, probability * (0.6 + (index * 0.1))));
        bar.style.height = `${height}%`;
    });
};

const updateSelectedForecast = (point) => {
    document.querySelectorAll('.forecast-point').forEach((item) => {
        const isSelected = item === point;

        item.classList.toggle('forecast-point-active', isSelected);
        item.setAttribute('aria-pressed', isSelected.toString());
    });

    const time = point.querySelector('span').textContent;
    const temperature = point.querySelector('strong').textContent;
    const condition = point.querySelector('small').textContent;

    setText('[data-weather-selected]', `${time} · ${temperature} · ${condition}`);
};

const loadWeather = async () => {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 8000);

    try {
        const response = await fetch(weatherApiUrl, { signal: controller.signal });

        if (!response.ok) {
            throw new Error(`Weather request failed with ${response.status}`);
        }

        const weather = await response.json();

        if (!hasForecastShape(weather)) {
            throw new Error('Weather response has an unexpected shape.');
        }

        const current = weather.current;
        const hourly = weather.hourly;
        const baseIndex = getClosestHourlyIndex(hourly.time, current.time);

        setText('[data-weather-place]', landingCoordinates.place);
        setText('[data-weather-temperature]', formatTemperature(current.temperature_2m));
        setText('[data-weather-condition]', describeWeather(current.weather_code));
        setText('[data-weather-state]', copy.updated);
        setText('[data-weather-updated]', copy.updatedNow);

        document.querySelectorAll('.forecast-point').forEach((point) => {
            updateForecastPoint(point, hourly, baseIndex);
        });

        const selectedPoint = document.querySelector('.forecast-point-active');

        if (selectedPoint) {
            updateSelectedForecast(selectedPoint);
        }
    } catch (error) {
        setText('[data-weather-state]', copy.offline);
        setText('[data-weather-updated]', copy.invalid);
        console.info('The live weather demo is unavailable; showing local example data.', error);
    } finally {
        window.clearTimeout(timeout);
    }
};

document.querySelectorAll('[data-current-year]').forEach((element) => {
    element.textContent = currentYear.toString();
});

document.querySelectorAll('.forecast-point').forEach((point) => {
    point.addEventListener('click', () => updateSelectedForecast(point));
});

const menuToggle = document.querySelector('.menu-toggle');
const siteNav = document.querySelector('.site-nav');

menuToggle?.addEventListener('click', () => {
    const isOpen = siteNav?.classList.toggle('is-open') ?? false;

    menuToggle.setAttribute('aria-expanded', isOpen.toString());
});

siteNav?.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
        siteNav.classList.remove('is-open');
        menuToggle?.setAttribute('aria-expanded', 'false');
    });
});

if (document.querySelector('.forecast-point')) {
    loadWeather();
}
