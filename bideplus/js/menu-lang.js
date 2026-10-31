I18n.applyTranslations();
document.getElementById('lang-toggle').addEventListener('click', function () {
    I18n.setLang(I18n.nextLang());
});
window.addEventListener('bide:lang', function () {
    I18n.applyTranslations();
});
