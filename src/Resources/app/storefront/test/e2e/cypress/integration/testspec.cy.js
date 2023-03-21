describe('Test: Something', () => {
    it('test something', () => {
        cy.visit('http://localhost')
        cy.get('.header-logo-picture > img')
            .should('have.attr', 'alt', 'Go to homepage')

    });
    it('tests something else', () => {
        expect(true).to.equal(true)
    });
});