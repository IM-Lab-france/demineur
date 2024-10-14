document.addEventListener("DOMContentLoaded", () => {
    const steps = [
        { step: "composer_dry_run", name: "Vérification de Composer (dry-run)" },
        { step: "composer_install", name: "Installation de Composer" },
        { step: "write_env", name: "Écriture du fichier .env" },
        { step: "verify_env", name: "Vérification du fichier .env" },
        { step: "db_connection", name: "Connexion à la base de données" },
        { step: "check_db_exists", name: "Vérification de l'existence de la base de données" },
        { step: "create_db", name: "Exécution du script SQL" },
        { step: "create_admin", name: "Création de l’utilisateur administrateur" }
    ];

    const resultsContainer = document.getElementById("results");

    if (!resultsContainer) {
        console.error("L'élément avec l'ID 'results' est introuvable dans le HTML.");
        return;
    }

    async function executeStep(stepInfo, data = {}) {
        try {
            const response = await fetch("install.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ step: stepInfo.step, ...data })
            });
            const result = await response.json();

            console.log("Étape envoyée :", stepInfo.step);
            console.log("Réponse reçue :", result);

            displayResult(stepInfo.name, result.success, result.message);
            return result.success;
        } catch (error) {
            displayResult(stepInfo.name, false, error.message);
            return false;
        }
    }

    function displayResult(stepName, success, message = "") {
        const stepDiv = document.createElement("div");
        stepDiv.classList.add("step-result", "d-flex", "align-items-center", "mb-2");

        const icon = document.createElement("span");
        icon.classList.add("mr-2", "icon");
        icon.innerHTML = success ? "✅" : "❌";
        icon.style.color = success ? "green" : "red";

        const stepText = document.createElement("span");
        stepText.innerText = `${stepName} : `;
        stepText.classList.add("font-weight-bold");

        const messageText = document.createElement("span");
        messageText.innerText = success ? "Succès" : message;
        messageText.classList.add(success ? "text-success" : "text-danger", "ml-2");

        stepDiv.appendChild(icon);
        stepDiv.appendChild(stepText);
        stepDiv.appendChild(messageText);
        resultsContainer.appendChild(stepDiv);
    }

    async function runInstallation() {
        resultsContainer.innerHTML = ""; // Effacer les résultats précédents

        // Étapes d'installation
        const envData = {
            server: document.getElementById("server").value,
            username: document.getElementById("username").value,
            password: document.getElementById("password").value,
            database: document.getElementById("database").value,
        };

        for (const stepInfo of steps) {
            let data = {};
            if (stepInfo.step === "write_env") data = envData;
            if (stepInfo.step === "create_admin") {
                data = {
                    admin_username: document.getElementById("adminUsername").value,
                    admin_password: document.getElementById("adminPassword").value
                };
            }

            const success = await executeStep(stepInfo, data);
            if (!success) return; // Arrêter si une étape échoue
        }

        // Redirection vers la page admin si toutes les étapes sont terminées
        setTimeout(() => {
            window.location.href = "/admin/index.php";
        }, 1000);
    }

    const installButton = document.getElementById("installButton");
    if (installButton) {
        installButton.addEventListener("click", (e) => {
            e.preventDefault();
            runInstallation();
        });
    }
});
