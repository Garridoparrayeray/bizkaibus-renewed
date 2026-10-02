I18n.applyTranslations();
document.getElementById('lang-toggle').addEventListener('click', function () {
    if (I18n.getLang() === 'eu') {
        I18n.setLang('es');
    } else {
        I18n.setLang('eu');
    }
    I18n.applyTranslations();
});
