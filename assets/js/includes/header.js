        // Mobile menu toggle
        const toggleButton = document.querySelector('.nav-toggle');
        const closeButton = document.querySelector('.nav-close');
        const nav = document.querySelector('header nav');

        toggleButton.addEventListener('click', () => {
            nav.classList.toggle('nav-open');
        });

        closeButton.addEventListener('click', () => {
            nav.classList.remove('nav-open');
        });

        // Profile dropdown toggle
        const profileToggle = document.getElementById('profileToggle');
        const dropdownMenu = document.getElementById('dropdownMenu');

        if (profileToggle && dropdownMenu) {
            profileToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                dropdownMenu.classList.toggle('show');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.profile-dropdown')) {
                    dropdownMenu.classList.remove('show');
                }
            });

            // Handle viewport changes
            function handleViewportChange() {
                if (window.matchMedia('(min-width: 769px)').matches) {
                    // Desktop view - hide dropdown
                    dropdownMenu.classList.remove('show');
                }
            }

            // Add event listener for viewport changes
            window.matchMedia('(min-width: 769px)').addEventListener('change', handleViewportChange);
            
            // Initial check
            handleViewportChange();
        }
