/**
 * HR Nomination Form Frontend Script.
 * Injects form action, nonces, honeypot, validation, submission spinner, and handles feedback banners.
 */
(function() {
    'use strict';

    if (typeof window === 'undefined') {
        return;
    }

    function initHRNomination() {
        const config = window.hrNominationData || {};
        const forms = findHRForms();

        if (!forms || forms.length === 0) {
            // Check if there is a feedback banner to display even if form selector is customized
            handleUrlFeedback(null);
            return;
        }

        forms.forEach(function(form) {
            setupForm(form, config);
        });

        // Display success or error banner if present in URL
        handleUrlFeedback(forms[0]);
    }

    /**
     * Locate existing nomination forms on page.
     * Looks for .hr-form, forms containing nomination keywords, or WPBakery raw HTML blocks.
     */
    function findHRForms() {
        const found = [];

        // 1. Explicit .hr-form class
        const elements = document.querySelectorAll('.hr-form');
        elements.forEach(function(el) {
            if (el.tagName.toLowerCase() === 'form') {
                if (!found.includes(el)) found.push(el);
            } else {
                // If container has a form child
                const childForm = el.querySelector('form');
                if (childForm) {
                    if (!found.includes(childForm)) found.push(childForm);
                } else {
                    // If .hr-form is a div containing form fields without a <form> tag, wrap it
                    const converted = wrapContainerInForm(el);
                    if (converted && !found.includes(converted)) found.push(converted);
                }
            }
        });

        // 2. Fallback check for any form with id or class containing hr-nomination
        const fallbackForms = document.querySelectorAll('form[id*="hr-nomination"], form[class*="hr-nomination"], form[id*="nomination"]');
        fallbackForms.forEach(function(f) {
            if (!found.includes(f)) found.push(f);
        });

        return found;
    }

    /**
     * If user placed raw inputs inside a <div class="hr-form"> without a <form> tag,
     * dynamically wrap the inputs inside a form element.
     */
    function wrapContainerInForm(container) {
        if (!container || container.querySelector('form')) return null;

        // Check if container has inputs or buttons
        const inputs = container.querySelectorAll('input, textarea, select, button');
        if (inputs.length === 0) return null;

        const form = document.createElement('form');
        form.className = 'hr-form hr-dynamic-form';

        while (container.firstChild) {
            form.appendChild(container.firstChild);
        }
        container.appendChild(form);

        return form;
    }

    /**
     * Configure action, hidden fields, honeypot, field mappings, and events on the form.
     */
    function setupForm(form, config) {
        if (!form || form.dataset.hrNominationInitialized === 'true') {
            return;
        }
        form.dataset.hrNominationInitialized = 'true';

        // 1. Set form action and method to WordPress admin-post.php
        form.setAttribute('action', config.postUrl || '/wp-admin/admin-post.php');
        form.setAttribute('method', 'POST');
        form.setAttribute('novalidate', 'novalidate');

        // 2. Inject hidden action field: 'hr_nomination_form'
        ensureHiddenField(form, 'action', config.action || 'hr_nomination_form');

        // 3. Inject security nonce
        if (config.nonce) {
            ensureHiddenField(form, 'hr_nomination_nonce', config.nonce);
        }

        // 4. Inject current return URL (stripped of previous nomination params)
        const currentUrl = cleanUrlParams(window.location.href);
        ensureHiddenField(form, 'return_url', currentUrl);

        // 5. Inject Spam Honeypot Field
        ensureHoneypotField(form, config.honeypotField || 'hr_nomination_hp');

        // 6. Smart field mapping: Ensure fields have appropriate name attributes
        mapFormFields(form);

        // 7. Attach submit event listener for validation and UI spinner
        form.addEventListener('submit', function(e) {
            handleFormSubmit(e, form, config);
        });
    }

    /**
     * Ensure a hidden input field exists inside form with given name and value.
     */
    function ensureHiddenField(form, name, value) {
        let input = form.querySelector(`input[type="hidden"][name="${name}"]`);
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.appendChild(input);
        }
        input.value = value;
    }

    /**
     * Inject an invisible honeypot field to trap spam bots.
     */
    function ensureHoneypotField(form, fieldName) {
        let hpWrap = form.querySelector('.hr-hp-wrapper');
        if (!hpWrap) {
            hpWrap = document.createElement('div');
            hpWrap.className = 'hr-hp-wrapper';
            hpWrap.style.cssText = 'position:absolute !important; left:-9999px !important; width:1px !important; height:1px !important; opacity:0 !important; overflow:hidden !important; pointer-events:none !important;';
            hpWrap.setAttribute('aria-hidden', 'true');

            const hpInput = document.createElement('input');
            hpInput.type = 'text';
            hpInput.name = fieldName;
            hpInput.value = '';
            hpInput.tabIndex = -1;
            hpInput.autocomplete = 'off';

            hpWrap.appendChild(hpInput);
            form.insertBefore(hpWrap, form.firstChild);
        }
    }

    /**
     * Smart Field Mapping.
     * Checks input fields and maps missing or generic name attributes to standard required names.
     */
    function mapFormFields(form) {
        const fields = form.querySelectorAll('input:not([type="hidden"]):not([type="submit"]), textarea, select');

        fields.forEach(function(field) {
            const currentName = (field.getAttribute('name') || '').toLowerCase();
            const id = (field.getAttribute('id') || '').toLowerCase();
            const placeholder = (field.getAttribute('placeholder') || '').toLowerCase();
            const type = (field.getAttribute('type') || '').toLowerCase();
            
            // Check associated label
            let labelText = '';
            if (field.id) {
                const label = form.querySelector(`label[for="${field.id}"]`);
                if (label) labelText = label.textContent.toLowerCase();
            }

            const identifier = `${currentName} ${id} ${placeholder} ${labelText}`.toLowerCase();

            // Only map if current name is missing, empty, or generic
            const isStandard = [
                'company_name', 'website', 'contact_person', 'job_title',
                'email', 'phone', 'category', 'company_overview', 'consent'
            ].includes(currentName);

            if (isStandard) {
                return;
            }

            if (!currentName || currentName.startsWith('input') || currentName.startsWith('field') || currentName === 'text') {
                if (type === 'checkbox' || identifier.includes('consent') || identifier.includes('agree') || identifier.includes('terms')) {
                    field.setAttribute('name', 'consent');
                } else if (type === 'email' || identifier.includes('email')) {
                    field.setAttribute('name', 'email');
                } else if (type === 'tel' || identifier.includes('phone') || identifier.includes('mobile') || identifier.includes('contact no')) {
                    field.setAttribute('name', 'phone');
                } else if (type === 'url' || identifier.includes('website') || identifier.includes('site') || identifier.includes('url')) {
                    field.setAttribute('name', 'website');
                } else if (identifier.includes('company') && (identifier.includes('name') || !identifier.includes('overview'))) {
                    field.setAttribute('name', 'company_name');
                } else if (identifier.includes('contact') || identifier.includes('person') || identifier.includes('full name') || identifier.includes('your name')) {
                    field.setAttribute('name', 'contact_person');
                } else if (identifier.includes('job') || identifier.includes('title') || identifier.includes('designation') || identifier.includes('role')) {
                    field.setAttribute('name', 'job_title');
                } else if (field.tagName.toLowerCase() === 'select' || identifier.includes('category') || identifier.includes('award')) {
                    field.setAttribute('name', 'category');
                } else if (field.tagName.toLowerCase() === 'textarea' || identifier.includes('overview') || identifier.includes('about') || identifier.includes('description') || identifier.includes('reason')) {
                    field.setAttribute('name', 'company_overview');
                }
            }
        });
    }

    /**
     * Handle submission validation, disable submit button, and display spinner.
     */
    function handleFormSubmit(e, form, config) {
        // Clear previous inline errors
        clearFieldErrors(form);

        const errors = validateForm(form, config);

        if (errors.length > 0) {
            e.preventDefault();
            displayInlineErrors(form, errors, config);
            return false;
        }

        // Form is valid: set submit button to loading state
        setSubmitButtonLoading(form, config);

        // Native POST to admin-post.php proceeds.
        return true;
    }

    /**
     * Validate all required fields according to specifications.
     */
    function validateForm(form, config) {
        const errors = [];
        const i18n = config.i18n || {};

        const requiredFields = [
            { name: 'company_name', label: 'Company Name' },
            { name: 'website', label: 'Website', type: 'url' },
            { name: 'contact_person', label: 'Contact Person' },
            { name: 'job_title', label: 'Job Title' },
            { name: 'email', label: 'Email', type: 'email' },
            { name: 'phone', label: 'Phone' },
            { name: 'category', label: 'Category' },
            { name: 'company_overview', label: 'Company Overview' },
            { name: 'consent', label: 'Consent', type: 'checkbox' }
        ];

        requiredFields.forEach(function(rule) {
            const field = getFieldByName(form, rule.name);

            if (!field) {
                // If field doesn't exist by exact name, we don't block unless form has it mapped
                return;
            }

            if (rule.type === 'checkbox') {
                if (!field.checked) {
                    errors.push({
                        field: field,
                        message: i18n.consentRequired || 'You must agree to the terms to proceed.'
                    });
                }
            } else {
                const val = (field.value || '').trim();
                if (!val) {
                    errors.push({
                        field: field,
                        message: (rule.label ? rule.label + ': ' : '') + (i18n.requiredField || 'This field is required.')
                    });
                } else if (rule.type === 'email') {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(val)) {
                        errors.push({
                            field: field,
                            message: i18n.validEmail || 'Please enter a valid email address.'
                        });
                    }
                } else if (rule.type === 'url') {
                    try {
                        const urlToTest = val.startsWith('http://') || val.startsWith('https://') ? val : 'https://' + val;
                        new URL(urlToTest);
                    } catch (err) {
                        errors.push({
                            field: field,
                            message: i18n.validUrl || 'Please enter a valid website URL.'
                        });
                    }
                }
            }
        });

        return errors;
    }

    /**
     * Helper to retrieve input/textarea/select by name or fallback alias.
     */
    function getFieldByName(form, name) {
        let el = form.querySelector(`[name="${name}"]`);
        if (el) return el;

        // Try common aliases
        const aliases = {
            company_name: ['company', 'company-name', 'companyname'],
            website: ['url', 'company_website', 'company-website', 'site'],
            contact_person: ['contact_name', 'name', 'full_name', 'contactperson'],
            job_title: ['title', 'designation', 'position', 'jobtitle'],
            email: ['contact_email', 'work_email', 'email_address'],
            phone: ['phone_number', 'telephone', 'mobile', 'tel'],
            category: ['award_category', 'nomination_category'],
            company_overview: ['overview', 'description', 'nomination_reason', 'about_company'],
            consent: ['agree', 'terms', 'privacy_consent']
        };

        if (aliases[name]) {
            for (let i = 0; i < aliases[name].length; i++) {
                el = form.querySelector(`[name="${aliases[name][i]}"]`);
                if (el) return el;
            }
        }

        return null;
    }

    /**
     * Render inline field error messages.
     */
    function displayInlineErrors(form, errors, config) {
        errors.forEach(function(item, index) {
            const field = item.field;
            field.classList.add('hr-input-error');

            let errSpan = field.parentNode.querySelector('.hr-field-error-msg');
            if (!errSpan) {
                errSpan = document.createElement('div');
                errSpan.className = 'hr-field-error-msg';
                field.parentNode.appendChild(errSpan);
            }
            errSpan.textContent = item.message;

            // Scroll to the first erroneous element
            if (index === 0) {
                field.focus();
                field.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        // Show brief top warning alert above form
        const banner = createAlertBanner(
            'error',
            config.i18n ? config.i18n.errorTitle : 'Submission Error',
            config.i18n ? config.i18n.validationError : 'Please fill in all required fields.'
        );
        insertFormBanner(form, banner);
    }

    /**
     * Clear previous error messages.
     */
    function clearFieldErrors(form) {
        form.querySelectorAll('.hr-input-error').forEach(function(el) {
            el.classList.remove('hr-input-error');
        });
        form.querySelectorAll('.hr-field-error-msg').forEach(function(el) {
            el.remove();
        });
        const existingBanner = form.parentNode.querySelector('.hr-nomination-alert');
        if (existingBanner && existingBanner.dataset.temporary === 'true') {
            existingBanner.remove();
        }
    }

    /**
     * Disable submit button and show spinner.
     */
    function setSubmitButtonLoading(form, config) {
        const submitBtn = form.querySelector('button[type="submit"], input[type="submit"], button:not([type="button"]):not([type="reset"])');
        if (!submitBtn) return;

        submitBtn.disabled = true;
        submitBtn.classList.add('hr-btn-loading');

        const originalText = submitBtn.tagName.toLowerCase() === 'input' ? submitBtn.value : submitBtn.innerHTML;
        form.dataset.hrOriginalBtnText = originalText;

        const loadingText = config.i18n ? config.i18n.submitting : 'Submitting Nomination...';

        if (submitBtn.tagName.toLowerCase() === 'input') {
            submitBtn.value = loadingText;
        } else {
            submitBtn.innerHTML = `
                <span class="hr-spinner" aria-hidden="true"></span>
                <span class="hr-btn-text">${loadingText}</span>
            `;
        }
    }

    /**
     * Inspect URL for nomination feedback parameters (?nomination=success or ?nomination=error)
     * and display rich floating/inline message banner.
     */
    function handleUrlFeedback(form) {
        const urlParams = new URLSearchParams(window.location.search);
        const nominationStatus = urlParams.get('nomination');

        if (!nominationStatus) {
            return;
        }

        const reason = urlParams.get('reason');
        const config = window.hrNominationData || {};
        const i18n = config.i18n || {};

        let banner;
        if (nominationStatus === 'success') {
            banner = createAlertBanner(
                'success',
                i18n.successTitle || 'Nomination Submitted Successfully!',
                i18n.successMessage || 'Thank you for submitting your nomination. Our team has received your information.'
            );
        } else {
            let errorMsg = i18n.defaultError || 'There was an issue processing your submission.';
            if (reason === 'rate_limit') {
                errorMsg = i18n.rateLimitError || 'Too many submissions. Please wait 60 seconds before submitting again.';
            } else if (reason === 'validation_failed') {
                errorMsg = i18n.validationError || 'Please check that all required fields are filled out correctly.';
            } else if (reason === 'invalid_nonce') {
                errorMsg = i18n.invalidNonce || 'Session expired. Please refresh the page and try again.';
            }

            banner = createAlertBanner(
                'error',
                i18n.errorTitle || 'Submission Could Not Be Completed',
                errorMsg
            );
        }

        // Insert above form or top of container
        insertFormBanner(form, banner);

        // Scroll to banner
        if (banner) {
            banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // Clean query parameter from browser address bar without reload
        cleanUrlHistory();
    }

    /**
     * Create an accessible, styled alert banner DOM element.
     */
    function createAlertBanner(type, title, message) {
        const alert = document.createElement('div');
        alert.className = `hr-nomination-alert hr-nomination-alert-${type}`;
        alert.setAttribute('role', type === 'success' ? 'status' : 'alert');

        const iconSvg = type === 'success'
            ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>'
            : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';

        alert.innerHTML = `
            <div class="hr-alert-icon">${iconSvg}</div>
            <div class="hr-alert-content">
                <div class="hr-alert-heading">${escapeHtml(title)}</div>
                <div class="hr-alert-text">${escapeHtml(message)}</div>
            </div>
            <button type="button" class="hr-alert-close" aria-label="Dismiss">&times;</button>
        `;

        alert.querySelector('.hr-alert-close').addEventListener('click', function() {
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 300);
        });

        return alert;
    }

    /**
     * Insert banner into DOM right before the form or at the top of content.
     */
    function insertFormBanner(form, banner) {
        if (!banner) return;

        if (form && form.parentNode) {
            form.parentNode.insertBefore(banner, form);
        } else {
            const target = document.querySelector('.hr-form') || document.querySelector('main') || document.querySelector('.content') || document.body;
            if (target && target.parentNode) {
                target.parentNode.insertBefore(banner, target);
            } else if (target) {
                target.insertBefore(banner, target.firstChild);
            }
        }
    }

    /**
     * Clean nomination query parameters from a URL string.
     */
    function cleanUrlParams(url) {
        try {
            const u = new URL(url);
            u.searchParams.delete('nomination');
            u.searchParams.delete('reason');
            return u.toString();
        } catch (e) {
            return url;
        }
    }

    /**
     * Clean browser address bar query params without reloading.
     */
    function cleanUrlHistory() {
        if (window.history && window.history.replaceState) {
            const url = new URL(window.location.href);
            if (url.searchParams.has('nomination') || url.searchParams.has('reason')) {
                url.searchParams.delete('nomination');
                url.searchParams.delete('reason');
                window.history.replaceState({}, document.title, url.toString());
            }
        }
    }

    /**
     * Escape HTML string helper.
     */
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initHRNomination);
    } else {
        initHRNomination();
    }
})();
