/**
 * onDOMLoaded routines
 */
document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelector('.attributes')) {
        let wcbvCookie = document.cookie;

        wcbvCookie = wcbvCookie.replace(/(?:(?:^|.*;\s*)wcbv\s*\=\s*([^;]*).*$)|^.*$/, "$1");

        document.getElementById('pa_shows').value = wcbvCookie;
    }
});
