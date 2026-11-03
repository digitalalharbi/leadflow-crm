/*
 * Two ways in. A landing page is opened by strangers from an ad, on a phone, and has seconds to appear: it gets its own small entry
 * and never downloads the product behind it. Everything else is the app.
 */
void (/^\/l\/[^/]+\/?$/.test(window.location.pathname) ? import('@/landing/main') : import('@/appMain'))
