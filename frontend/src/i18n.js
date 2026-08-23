import { createI18n } from 'vue-i18n';
import de from './locales/de.json';
import en from './locales/en.json';

// Modul #11 Mehrsprachigkeit: Composition-API-Modus (legacy: false), damit
// useI18n() in <script setup> nutzbar ist -- Fallback auf Deutsch, falls in
// Englisch ein Schluessel fehlt, statt eine leere Stelle anzuzeigen.
const STORAGE_KEY = 'portal-locale';

function initialLocale() {
    const saved = localStorage.getItem(STORAGE_KEY);
    return saved === 'en' ? 'en' : 'de';
}

const i18n = createI18n({
    legacy: false,
    globalInjection: true,
    locale: initialLocale(),
    fallbackLocale: 'de',
    messages: { de, en },
});

// Sprachumschalter (AppShell) + <html lang> fuer Screenreader/Browser.
export function setLocale(locale) {
    i18n.global.locale.value = locale;
    localStorage.setItem(STORAGE_KEY, locale);
    document.documentElement.lang = locale;
}

export function currentLocale() {
    return i18n.global.locale.value;
}

// 'de' -> 'de-DE', 'en' -> 'en-GB' fuer Intl.DateTimeFormat/toLocaleDateString-
// Aufrufe in den Kalender-Komponenten (Tag/Monat/Wochentag-Formatierung).
export function bcp47Locale() {
    return currentLocale() === 'en' ? 'en-GB' : 'de-DE';
}

export default i18n;
