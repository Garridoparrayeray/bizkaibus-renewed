// La red de esta app, el estado compartido y los elementos de la página.
export const NETWORK_SLUG = window.__bbNetwork;
export const NETWORK = BideNetworks.get(NETWORK_SLUG);
export const FEATURES = NETWORK.features;
export const HAS_PLATFORMS = FEATURES.platforms;
export const FAVORITES_STORAGE_KEY = NETWORK.favoritesKey;
export const networkQuery = networkQueryFor(NETWORK_SLUG);
export const VEHICLE_PLURAL = { bus: 'autobuses', metro: 'metros', tram: 'tranvías', train: 'trenes' };

export const ICONS = {
    pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-7.5-7-12a7 7 0 0 1 14 0c0 4.5-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>',
    bus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="12" rx="2"/><line x1="3" y1="11" x2="21" y2="11"/><circle cx="7.5" cy="19" r="1.5"/><circle cx="16.5" cy="19" r="1.5"/></svg>',
    heartOutline: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.3C.6 8.1 2.3 5 5.6 5 8 5 10 6.6 12 9c2-2.4 4-4 6.4-4 3.3 0 5 3.1 3.6 6.7C19.5 16.4 12 21 12 21z"/></svg>',
    heartFilled: '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 21s-7.5-4.6-10-9.3C.6 8.1 2.3 5 5.6 5 8 5 10 6.6 12 9c2-2.4 4-4 6.4-4 3.3 0 5 3.1 3.6 6.7C19.5 16.4 12 21 12 21z"/></svg>',
};

export const state = {
    currentStop: null,
    currentLine: null,
    timetableStopId: null,
    favoriteKeys: new Set(),
    departuresRefreshTimer: null,
    departuresTickTimer: null,
    lastDepartures: null,
    lastTimetableEntries: null,
    departuresSignature: '',
};

export const el = {
    homeLink: document.getElementById('home-link'),
    networkSwitch: document.getElementById('network-switcher'),
    menuOpen: document.getElementById('menu-open'),
    langToggle: document.getElementById('lang-toggle'),
    appSubtitle: document.getElementById('app-subtitle'),
    menuClose: document.getElementById('menu-close'),
    sideMenu: document.getElementById('side-menu'),
    menuFavoritesOpen: document.getElementById('menu-favorites-open'),
    menuAlertsEmpty: document.getElementById('menu-alerts-empty'),
    menuAlertsList: document.getElementById('menu-alerts-list'),
    favoritesClose: document.getElementById('favorites-close'),
    favoritesPanel: document.getElementById('favorites-panel'),
    searchForm: document.getElementById('search-form'),
    searchInput: document.getElementById('search-input'),
    searchResults: document.getElementById('search-results'),
    nearbyBtn: document.getElementById('nearby-btn'),
    favoritesList: document.getElementById('favorites-list'),
    favoritesEmpty: document.getElementById('favorites-empty'),
    liveCard: document.getElementById('live-card'),
    liveEmpty: document.getElementById('live-empty'),
    liveBadge: document.getElementById('live-badge'),
    liveFavorite: document.getElementById('live-favorite'),
    liveScreen: document.getElementById('live-screen'),
    liveClose: document.getElementById('live-close'),
    liveLine: document.getElementById('live-line'),
    liveHeadsign: document.getElementById('live-headsign'),
    liveOpenDetail: document.getElementById('live-open-detail'),
    liveTimetableLink: document.getElementById('live-timetable-link'),
    liveMinutes: document.getElementById('live-minutes'),
    liveDelay: document.getElementById('live-delay'),
    liveStatusDot: document.getElementById('live-status-dot'),
    liveStatusText: document.getElementById('live-status-text'),
    liveIncidentsLink: document.getElementById('live-incidents-link'),
    liveMoreList: document.getElementById('live-more-list'),
    liveOfflineMarks: document.getElementById('live-offline-marks'),
    liveMark: document.getElementById('live-mark'),
    liveMarksUndo: document.getElementById('live-marks-undo'),
    platformMarksUndo: document.getElementById('platform-marks-undo'),
    platformPanel: document.getElementById('platform-panel'),
    platformPanelStop: document.getElementById('platform-panel-stop'),
    platformFavorite: document.getElementById('platform-favorite'),
    platformScreen: document.getElementById('platform-screen'),
    platformClose: document.getElementById('platform-close'),
    platformTimetableLink: document.getElementById('platform-timetable-link'),
    platformTimetableLines: document.getElementById('platform-timetable-lines'),
    platformColumns: document.getElementById('platform-columns'),
    timetableSection: document.getElementById('timetable-section'),
    timetableFavorite: document.getElementById('timetable-favorite'),
    timetableClose: document.getElementById('timetable-close'),
    timetableLine: document.getElementById('timetable-line'),
    timetableBody: document.getElementById('timetable-body'),
    timetableEmpty: document.getElementById('timetable-empty'),
    lineMapEmpty: document.getElementById('line-map-empty'),
    filterDate: document.getElementById('filter-date'),
    filterHourFrom: document.getElementById('filter-hour-from'),
    filterHourTo: document.getElementById('filter-hour-to'),
    scheduleTextToggle: document.getElementById('schedule-text-toggle'),
    attribution: document.getElementById('attribution'),
    disclaimer: document.getElementById('disclaimer'),
    lineMap: document.getElementById('line-map'),
    scheduleModal: document.getElementById('schedule-modal'),
    scheduleModalClose: document.getElementById('schedule-modal-close'),
    scheduleModalLine: document.getElementById('schedule-modal-line'),
    scheduleModalContent: document.getElementById('schedule-modal-content'),
    modal: document.getElementById('vehicle-modal'),
    modalClose: document.getElementById('modal-close'),
    modalBadge: document.getElementById('modal-badge'),
    modalLine: document.getElementById('modal-line'),
    modalHeadsign: document.getElementById('modal-headsign'),
    modalVehicle: document.getElementById('modal-vehicle'),
    modalShare: document.getElementById('modal-share'),
    modalNotify: document.getElementById('modal-notify'),
    modalNotifyOptions: document.getElementById('modal-notify-options'),
    modalNotifyRepeat: document.getElementById('modal-notify-repeat'),
    modalNotifyRepeatInput: document.getElementById('modal-notify-repeat-input'),
    modalNotifyCancel: document.getElementById('modal-notify-cancel'),
    modalNotifyStatus: document.getElementById('modal-notify-status'),
    modalAlertsToggle: document.getElementById('modal-alerts-toggle'),
    modalAlerts: document.getElementById('modal-alerts'),
    modalStops: document.getElementById('modal-stops'),
    netBanner: document.getElementById('net-banner'),
};

// La consulta ?red= que llevan las direcciones de esta app (la red por defecto no la lleva).
function networkQueryFor(slug) {
    if (slug !== BideNetworks.DEFAULT) return '?red=' + slug;
    return '';
}
