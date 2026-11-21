const mix = require('laravel-mix');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel applications. By default, we are compiling the CSS
 | file for the application as well as bundling up all the JS files.
 |
 */

// Bootstrap 4 is built on Sass @import and global colour functions, which
// Dart Sass deprecates ahead of 3.0. They still compile, so hide those
// notices until the UI moves to Bootstrap 5 and the Sass module system.
const sassOptions = {
    sassOptions: {
        quietDeps: true,
        silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function', 'abs-percent'],
    },
};

mix.js('resources/js/vendor-frontend.js', 'public/js')
    .js('resources/js/frontend.js', 'public/js')
    .js('resources/js/dashboard-google-maps.js', 'public/js')
    .js('resources/js/bootstrap.js', 'public/js')
    .js('resources/js/admin.js', 'public/js')
    .sass('resources/sass/auth.scss', 'public/css', sassOptions)
    .sass('resources/sass/admin.scss', 'public/css', sassOptions)
    .sass('resources/sass/front-end/frontend.scss', 'public/css', sassOptions)
    .sass('resources/sass/bengali.scss', 'public/css', sassOptions);
