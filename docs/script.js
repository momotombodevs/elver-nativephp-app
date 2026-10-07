const currentYear = new Date().getFullYear();
const landingCoordinates = { latitude: 12.1364, longitude: -86.2514, place: 'Managua' };
const weatherApiUrl = new URL('https://api.open-meteo.com/v1/forecast');

weatherApiUrl.search = new URLSearchParams({
    latitude: landingCoordinates.latitude.toString(),
    longitude: landingCoordinates.longitude.toString(),
    current: 'temperature_2m,weather_code',
    hourly: 'temperature_2m,precipitation_probability,weather_code',
    forecast_days: '2',
    timezone: 'auto',
}).toString();

const weatherDescriptions = new Map([
    [0, 'Despejado'], [1, 'Mayormente despejado'], [2, 'Parcialmente nublado'], [3, 'Nublado'],
    [45, 'Neblina'], [48, 'Neblina'], [51, 'Llovizna ligera'], [53, 'Llovizna'], [55, 'Llovizna intensa'],
    [61, 'Lluvia ligera'], [63, 'Lluvia'], [65, 'Lluvia intensa'], [80, 'Chubascos ligeros'],
    [81, 'Chubascos'], [82, 'Chubascos intensos'], [95, 'Tormenta'], [96, 'Tormenta con granizo'], [99, 'Tormenta intensa'],
]);

const setText = (selector, value) => {
    document.querySelectorAll(selector).forEach((element) => {
        element.textContent = value;
    });
};

const formatTemperature = (value) => `${Math.round(Number(value))}°`;

const formatHour = (isoTime) => new Intl.DateTimeFormat('es-NI', {
    hour: 'numeric',
    hour12: false,
}).format(new Date(isoTime));

const describeWeather = (code) => weatherDescriptions.get(Number(code)) ?? 'Condición variable';

const getClosestHourlyIndex = (times, currentTime) => {
    const currentTimestamp = new Date(currentTime).getTime();

    return times.reduce((closestIndex, time, index) => {
        const closestDistance = Math.abs(new Date(times[closestIndex]).getTime() - currentTimestamp);
        const distance = Math.abs(new Date(time).getTime() - currentTimestamp);

        return distance < closestDistance ? index : closestIndex;
    }, 0);
};

const updateForecastPoint = (point, hourly, baseIndex) => {
    const offset = Number(point.dataset.hourIndex);
    const hourlyIndex = Math.min(baseIndex + offset, hourly.time.length - 1);
    const temperature = hourly.temperature_2m[hourlyIndex];
    const description = describeWeather(hourly.weather_code[hourlyIndex]);
    const timeLabel = offset === 0 ? 'Ahora' : offset === 24 ? '+24h' : formatHour(hourly.time[hourlyIndex]);
    const probability = Number(hourly.precipitation_probability?.[hourlyIndex] ?? 0);

    point.querySelector('span').textContent = timeLabel;
    point.querySelector('strong').textContent = formatTemperature(temperature);
    point.querySelector('small').textContent = description;
    point.dataset.precipitationProbability = probability.toString();

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
        const current = weather.current;
        const hourly = weather.hourly;
        const baseIndex = getClosestHourlyIndex(hourly.time, current.time);

        setText('[data-weather-place]', landingCoordinates.place);
        setText('[data-weather-temperature]', formatTemperature(current.temperature_2m));
        setText('[data-weather-condition]', describeWeather(current.weather_code));
        setText('[data-weather-state]', 'Actualizado');
        setText('[data-weather-updated]', 'Actualizado ahora');

        document.querySelectorAll('.forecast-point').forEach((point) => {
            updateForecastPoint(point, hourly, baseIndex);
        });

        const selectedPoint = document.querySelector('.forecast-point-active');

        if (selectedPoint) {
            updateSelectedForecast(selectedPoint);
        }
    } catch (error) {
        setText('[data-weather-state]', 'Ejemplo offline');
        setText('[data-weather-updated]', 'Ejemplo sin conexión');
        console.info('El pronóstico en vivo no está disponible; se muestra el ejemplo local.', error);
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

loadWeather();
