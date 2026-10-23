/**
 * Configuracion de Tailwind (solo para desarrollo).
 * Para regenerar assets/css/tailwind.css despues de cambiar clases:
 *   npm install && npm run build:css
 * En el hosting NO se necesita Node: se sube el CSS ya compilado.
 */
module.exports = {
  content: ['./*.php', './includes/**/*.php', './assets/js/**/*.js'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Plus Jakarta Sans"', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
