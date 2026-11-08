// Loaded as a classic, blocking script in <head> (not as a module): it has to set the theme
// before the first paint, otherwise a stored "dark" would flash light on every page load.
// "system" (or nothing stored) leaves data-theme unset, so the CSS media query decides.
(function () {
    var root = document.documentElement;
    root.classList.add('js');
    var theme = null;
    try {
        var storage = window.localStorage;
        theme = storage.getItem('zwille-theme');
        // One-time move from the key used before the rename (formerly EatMyShort.Link).
        var legacy = storage.getItem('ems-theme');
        if (legacy !== null) {
            if (theme === null) {
                theme = legacy;
                storage.setItem('zwille-theme', legacy);
            }
            storage.removeItem('ems-theme');
        }
    } catch (e) {
        // Storage blocked (private mode, policy): fall back to the system setting.
    }
    if (theme === 'light' || theme === 'dark') {
        root.setAttribute('data-theme', theme);
    }
}());
