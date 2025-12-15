// Tag google manager
window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-W94HVDVD1L');

// MODAL MOBILE MENU
const menuBtn = document.getElementById("menu-btn");
const mobileMenu = document.getElementById("mobile-menu");
const closeBtn = document.getElementById("close-btn");

menuBtn.addEventListener("click", function () {
  mobileMenu.classList.add("open");
});

closeBtn.addEventListener("click", function () {
  mobileMenu.classList.remove("open");
});

const mobileMenuLinks = mobileMenu.querySelectorAll("nav a");
mobileMenuLinks.forEach(function (link) {
  link.addEventListener("click", function () {
    mobileMenu.classList.remove("open");
  });
});

// SCRIPT HOVER HEADER BRANCO
const headerBg = document.getElementById("header-bg");
const navLinks = document.getElementById("nav-links");
const ctaBtn = document.getElementById("cta-btn");

// ANIMAÇÃO DE CONTAGEM PARA A SEÇÃO DE ESTATÍSTICAS
/**
 * Anima um número de 0 até um valor final dentro de um elemento HTML.
 * @param {HTMLElement} el O elemento (ex: <h3>) que contém o texto a ser animado.
 * @param {number} duration A duração da animação em milissegundos.
 */
function animateCountUp(el, duration = 2000) {
    const originalText = el.textContent;
    
    // Extrai o número do texto, removendo caracteres não numéricos.
    let targetNumber = parseInt(originalText.replace(/[^\d]/g, ''), 10);

    // Lida com casos especiais como "mil".
    if (originalText.toLowerCase().includes('mil')) {
        targetNumber *= 1000;
    }

    if (isNaN(targetNumber)) {
        console.error("Não foi possível extrair um número válido de:", originalText);
        return;
    }

    // Separa o prefixo (ex: "+ de ") e o sufixo (ex: " Estados").
    const prefix = originalText.match(/^[^\d]*/)?.[0] || '';
    const suffix = originalText.match(/\d([^\d].*)/)?.[1] || '';

    let startTime = null;

    const step = (currentTime) => {
        if (!startTime) {
            startTime = currentTime;
        }

        const progress = Math.min((currentTime - startTime) / duration, 1);
        const currentNumber = Math.floor(progress * targetNumber);

        // Formata o número com separador de milhar (ex: 5.000)
        const formattedNumber = currentNumber.toLocaleString('pt-BR');

        // Remonta o texto com o número atualizado
        el.textContent = `${prefix}${formattedNumber}${suffix}`;

        if (progress < 1) {
            window.requestAnimationFrame(step);
        } else {
            // Garante que o texto final seja exatamente o original para manter a formatação.
            el.textContent = originalText;
        }
    };

    window.requestAnimationFrame(step);
}

// Usa IntersectionObserver para iniciar a animação quando a seção estiver visível.
document.addEventListener('DOMContentLoaded', () => {
    const statsSection = document.getElementById('stats-section');
    if (!statsSection) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const h3Elements = statsSection.querySelectorAll('h3');
                h3Elements.forEach(h3 => animateCountUp(h3));
                observer.unobserve(statsSection); // Anima apenas uma vez
            }
        });
    }, { threshold: 0.5 }); // Inicia quando 50% da seção estiver visível

    observer.observe(statsSection);
});

// modal services
(function () {
  const modal = document.getElementById("modal");
  if (!modal) return;

  const modalBox = modal.querySelector(".services-modal-box");
  const modalTitle = document.getElementById("modal-title");
  const modalContent = document.getElementById("modal-content");
  const closeBtns = [
    document.getElementById("closeModalBtn"),
    document.getElementById("closeModalBtn2")
  ];

  function openModal(card) {
    modalTitle.textContent = card.getAttribute("data-title") || "Serviço";
    modalContent.textContent = card.getAttribute("data-content") || "";

    modal.classList.add("active");
    setTimeout(() => modalBox.classList.add("visible"), 10);
  }

  function closeModal() {
    modalBox.classList.remove("visible");
    setTimeout(() => modal.classList.remove("active"), 200);
  }

  document.querySelectorAll(".open-modal").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      const card = e.target.closest("[data-title]");
      if (card) openModal(card);
    });
  });

  closeBtns.forEach((btn) => {
    if (btn) btn.addEventListener("click", closeModal);
  });

  modal.addEventListener("click", (e) => {
    if (e.target === modal) closeModal();
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && modal.classList.contains("active")) {
      closeModal();
    }
  });
})();

// Não exebi o mapa no IE
var isIE = /MSIE|Trident/.test(navigator.userAgent);

if (isIE) {
  document.querySelector(".location-map-inner").style.display = "none";
}
