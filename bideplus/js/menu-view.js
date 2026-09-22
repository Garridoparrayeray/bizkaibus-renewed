(function () {
    'use strict';

    var view = 'tiles';
    try {
        if (localStorage.getItem('bide_menu_view') === 'list') {
            view = 'list';
        }
    } catch (e) {
        view = 'tiles';
    }
    document.documentElement.setAttribute('data-view', view);
})();
