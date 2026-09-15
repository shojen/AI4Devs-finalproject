/*
 * Easter egg:
 * Cada clic sobre el café aumenta el contador.
 */

const coffee = document.getElementById('coffee');
const counter = document.getElementById('coffeeCount');

let coffees = 42;

coffee.addEventListener('click', () => {
    coffees++;

    counter.textContent = coffees;

    if (coffees === 50) {
        coffee.innerHTML = '☕ <code>¡Necesito vacaciones!</code>';
    }

    if (coffees === 100) {
        coffee.innerHTML = '💀 <code>Developer.exe stopped working</code>';
    }
});


/*
 * Si el usuario pulsa CTRL + SHIFT + I...
 */

document.addEventListener('keydown', (event) => {

    if (
        event.ctrlKey &&
        event.shiftKey &&
        event.key.toLowerCase() === 'i'
    ) {
        console.log(`
╔══════════════════════════════════════╗
║        👀 ¡Hola, developer!          ║
╠══════════════════════════════════════╣
║                                      ║
║  Sí, sabemos que estás mirando       ║
║  las DevTools.                       ║
║                                      ║
║  La web estará lista cuando          ║
║  deje de decir:                      ║
║                                      ║
║       "funciona en mi máquina"       ║
║                                      ║
╚══════════════════════════════════════╝
        `);
    }

});
