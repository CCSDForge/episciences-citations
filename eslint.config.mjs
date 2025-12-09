// eslint.config.js
import eslint from '@eslint/js';
import globals from 'globals';
import babelParser from '@babel/eslint-parser';
import path from 'path';
import { fileURLToPath } from 'url';

// Définition de __dirname pour les modules ES (ESM)
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export default [
  // 1. Les règles recommandées de base de ESLint
  eslint.configs.recommended,

  {
    files: ["**/*.js"],
    languageOptions: {
      // "parser": "@babel/eslint-parser"
      parser: babelParser, // <-- Utilisation du parseur Babel

      // Les options pour le parseur (comme "requireConfigFile": false)
      parserOptions: {
        requireConfigFile: false,
        ecmaVersion: 12, //
        sourceType: "module", //
      },

      // Les environnements globaux ("browser": true, "es2021": true, "jest": true)
      globals: {
        ...globals.browser,
        ...globals.es2021,
        ...globals.jest,
      },
    },

    // Vos règles personnalisées
    rules: {
      "indent": ["error", 4], //
      "linebreak-style": ["error", "unix"], //
      "quotes": ["error", "single"], //
      "semi": ["error", "always"], //
      "no-unused-vars": ["warn"], //
      "no-console": ["warn"] //
    },
  },
  // 3. Ignorer les fichiers ("ignorePatterns": [...])
  {
    ignores: ["node_modules/", "public/build/", "var/"] //
  }
];