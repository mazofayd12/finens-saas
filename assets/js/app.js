/**
 * Finens SaaS - Client Interactions & PWA Handlers
 */

// PWA Service Worker Registration
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('./sw.js')
            .then(reg => {
                console.log('Service Worker Registered successfully:', reg.scope);
            })
            .catch(err => {
                console.log('Service Worker registration failed:', err);
            });
    });
}

// PWA Install Prompt handling
let deferredInstallPrompt = null;
const installBanner = document.getElementById('pwa-install-banner');
const installButton = document.getElementById('pwa-install-btn');

window.addEventListener('beforeinstallprompt', (e) => {
    // Prevent the mini-infobar from appearing on mobile
    e.preventDefault();
    deferredInstallPrompt = e;
    
    // Show custom install banner if present
    if (installBanner) {
        installBanner.classList.remove('hidden');
    }
    if (installButton) {
        installButton.classList.remove('hidden');
    }
});

function triggerPwaInstall() {
    if (!deferredInstallPrompt) {
        alert('التطبيق جاهز أو مثبت بالفعل، يمكنك إضافته من خيارات المتصفح (إضافة للشاشة الرئيسية)');
        return;
    }
    deferredInstallPrompt.prompt();
    deferredInstallPrompt.userChoice.then((choiceResult) => {
        if (choiceResult.outcome === 'accepted') {
            console.log('User accepted the PWA install prompt');
            if (installBanner) installBanner.classList.add('hidden');
        }
        deferredInstallPrompt = null;
    });
}

// Mobile Sidebar & FAB Menu Helpers
window.openMobileSidebar = function() {
    const d = document.getElementById('mobile-sidebar-drawer');
    if (d) {
        d.classList.remove('hidden');
        d.classList.add('flex');
        document.body.style.overflow = 'hidden';
    }
};

window.closeMobileSidebar = function() {
    const d = document.getElementById('mobile-sidebar-drawer');
    if (d) {
        d.classList.add('hidden');
        d.classList.remove('flex');
        document.body.style.overflow = 'auto';
    }
};

window.toggleMobileFabMenu = function() {
    const sheet = document.getElementById('mobile-quick-actions-sheet');
    const icon = document.getElementById('fab-icon');
    if (!sheet) return;
    if (sheet.classList.contains('hidden')) {
        sheet.classList.remove('hidden');
        sheet.classList.add('flex');
        if (icon) icon.classList.add('rotate-45');
        document.body.style.overflow = 'hidden';
    } else {
        window.closeMobileFabMenu();
    }
};

window.closeMobileFabMenu = function() {
    const sheet = document.getElementById('mobile-quick-actions-sheet');
    const icon = document.getElementById('fab-icon');
    if (!sheet) return;
    sheet.classList.add('hidden');
    sheet.classList.remove('flex');
    if (icon) icon.classList.remove('rotate-45');
    document.body.style.overflow = 'auto';
};

// Modal Helpers
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = 'auto';
    }
}

// Close modal when clicking outside modal box
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-backdrop')) {
        e.target.closest('.modal-container')?.classList.add('hidden');
        e.target.closest('.modal-container')?.classList.remove('flex');
        document.body.style.overflow = 'auto';
    }
});

// Auto-hide alert messages after 5 seconds
document.addEventListener('DOMContentLoaded', () => {
    const alerts = document.querySelectorAll('.flash-alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });
});
