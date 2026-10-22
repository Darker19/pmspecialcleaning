const form = document.getElementById("contactForm");
const button = form.querySelector("button[type=submit]");
const status = form.querySelector(".form-status");

// Tijdstip waarop het formulier geladen is; de server weigert verzendingen binnen een paar seconden (bots).
document.getElementById("form_start").value = Math.floor(Date.now() / 1000);

form.addEventListener("submit", async (event) => {
    event.preventDefault();

    button.disabled = true;
    button.textContent = "Versturen...";
    status.textContent = "";
    status.className = "form-status";

    try {
        const response = await fetch(form.action, {
            method: "POST",
            body: new FormData(form)
        });

        if (response.ok) {
            form.reset();
            button.textContent = "Verstuurd";
            status.textContent = "Bedankt voor uw bericht. Wij nemen zo snel mogelijk contact met u op.";
            status.classList.add("is-success");
            return;
        }
        throw new Error(response.status);
    } catch (error) {
        button.disabled = false;
        button.textContent = "Opnieuw proberen";
        status.textContent = "Het versturen is niet gelukt. Probeer het later opnieuw of mail ons direct.";
        status.classList.add("is-error");
    }
});
