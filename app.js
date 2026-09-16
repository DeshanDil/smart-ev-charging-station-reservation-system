/* =========================================================
   Smart EV Charging System - UI Interactions
========================================================= */

document.addEventListener("DOMContentLoaded", function () {
    animatePageElements();
    setActiveNavigation();
    improveTables();
});

/* Smooth page entrance */
function animatePageElements() {
    const items = document.querySelectorAll(
        ".card, .recommend-box, form, .table-wrapper, .success, .error"
    );

    items.forEach((item, index) => {
        item.style.opacity = "0";
        item.style.transform = "translateY(14px)";

        setTimeout(() => {
            item.style.transition = "0.45s ease";
            item.style.opacity = "1";
            item.style.transform = "translateY(0)";
        }, index * 45);
    });
}

/* Highlight current page in navigation */
function setActiveNavigation() {
    const currentPage = window.location.pathname.split("/").pop();
    const links = document.querySelectorAll(".nav a");

    links.forEach((link) => {
        const linkPage = link.getAttribute("href").split("/").pop().split("?")[0];

        if (linkPage === currentPage) {
            link.classList.add("active");
        }
    });
}

/* Add better table behaviour */
function improveTables() {
    const tables = document.querySelectorAll("table");

    tables.forEach((table) => {
        table.setAttribute("role", "table");

        const rows = table.querySelectorAll("tr");

        rows.forEach((row, index) => {
            if (index === 0) return;

            row.addEventListener("mouseenter", () => {
                row.style.cursor = "default";
            });
        });
    });
}

/* Reusable table search function */
function smartSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);

    if (!input || !table) return;

    input.addEventListener("keyup", function () {
        const filter = input.value.toLowerCase();
        const rows = table.querySelectorAll("tr");

        rows.forEach((row, index) => {
            if (index === 0) return;

            const text = row.innerText.toLowerCase();
            row.style.display = text.includes(filter) ? "" : "none";
        });
    });
}

/* Confirmation dialog */
function confirmAction(message) {
    return confirm(message);
}