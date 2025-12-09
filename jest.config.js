module.exports = {
  testEnvironment: 'jsdom',
  roots: ['<rootDir>/assets'],
  testMatch: ['**/__tests__/**/*.test.js', '**/__tests__/**/*.spec.js'],
  testPathIgnorePatterns: ['/node_modules/', '/assets/__tests__/setup.js'],
  collectCoverageFrom: [
    'assets/**/*.js',
    '!assets/app.js',
    '!assets/bootstrap.js',
    '!assets/__tests__/**',
    '!assets/js/extract.js', // TODO: Add tests for this legacy file (426 lines)
  ],
  coverageThreshold: {
    global: {
      branches: 50,
      functions: 60,
      lines: 60,
      statements: 60
    }
  },
  setupFilesAfterEnv: ['<rootDir>/assets/__tests__/setup.js'],
  transform: {
    '^.+\\.js$': 'babel-jest',
  },
  moduleNameMapper: {
    '^sortablejs/(.*)$': '<rootDir>/node_modules/sortablejs/$1'
  }
};
