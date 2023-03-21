const { defineConfig } = require('cypress')

module.exports = defineConfig({
    e2e: {
        baseUrl: 'http://localhost',
        specPattern: 'cypress/integration/*.cy.{js,jsx,ts,tsx}',
        testIsolation: false
    },
})