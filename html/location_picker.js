/**
 * location_picker.js - Dual-Panel Uber-Grade Interactive Map & Search Picker
 */

const LocationPicker = (function () {
    let modalEl = null;
    let map = null;
    let marker = null;
    let currentCallback = null;
    let searchDebounceTimer = null;
    let isMapInitialized = false;
    let isDestinationMode = false;
    let savedScrollY = 0;

    // Current selection state
    let selectedPlace = {
        name: 'BRAC University',
        address: 'Kha 224 Pragati Sarani, Merul Badda, Dhaka-1212',
        lat: 23.7781,
        lng: 90.4265
    };

    function ensureLeafletLoaded(callback) {
        if (typeof L !== 'undefined') {
            callback();
            return;
        }

        // Auto-inject Leaflet CSS if missing
        if (!document.getElementById('leaflet-css-bundle')) {
            const link = document.createElement('link');
            link.id = 'leaflet-css-bundle';
            link.rel = 'stylesheet';
            link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.appendChild(link);
        }

        // Auto-inject Leaflet JS if missing
        if (!document.getElementById('leaflet-js-bundle')) {
            const script = document.createElement('script');
            script.id = 'leaflet-js-bundle';
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.onload = callback;
            document.head.appendChild(script);
        } else {
            const existingScript = document.getElementById('leaflet-js-bundle');
            existingScript.addEventListener('load', callback);
        }
    }

    function createModalDOM() {
        if (document.getElementById('uberLocationPickerModal')) {
            modalEl = document.getElementById('uberLocationPickerModal');
            return;
        }

        const html = `
        <div id="uberLocationPickerModal" class="uber-app-overlay" style="display: none;">
            <div class="uber-app-container">
                
                <!-- Modal Header Bar -->
                <div class="uber-picker-top-header">
                    <div class="uber-header-brand">
                        <div class="uber-header-badge" id="uberHeaderBadge">📍</div>
                        <div>
                            <h3 id="uberModalTitle" class="uber-modal-title">Where are you starting from?</h3>
                            <p class="uber-modal-subtitle">Search a place or tap anywhere on the map to drop a pin</p>
                        </div>
                    </div>
                    <button type="button" class="uber-close-icon-btn" id="pickerCloseBtn" title="Close (Esc)">&times;</button>
                </div>

                <!-- Modal Body: Left Search & Selection Panel + Right Live Map View -->
                <div class="uber-picker-split-body">
                    
                    <!-- Left Column: Search & Place Selector -->
                    <div class="uber-left-search-column">
                        
                        <!-- Search Box Input -->
                        <div class="uber-search-box-wrap">
                            <span class="uber-search-leading-icon" id="searchDotIcon">📍</span>
                            <input type="text" id="pickerSearchInput" class="uber-search-textfield" placeholder="Search Mohammadpur, Mirpur, Dhanmondi..." autocomplete="off" spellcheck="false">
                            <button type="button" id="pickerClearSearchBtn" class="uber-clear-icon-btn" style="display: none;" title="Clear">&times;</button>
                        </div>

                        <!-- GPS Button -->
                        <button type="button" class="uber-gps-action-btn" id="pickerCurrentLocBtn">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="22" y1="12" x2="18" y2="12"></line>
                                <line x1="6" y1="12" x2="2" y2="12"></line>
                                <line x1="12" y1="6" x2="12" y2="2"></line>
                                <line x1="12" y1="22" x2="12" y2="18"></line>
                            </svg>
                            <span>Use My Current GPS Location</span>
                        </button>

                        <!-- Quick Popular Dhaka Hubs -->
                        <div class="uber-pills-label">QUICK DHAKA HUBS:</div>
                        <div class="uber-quick-pills-wrap">
                            <button type="button" class="uber-pill-chip" onclick="LocationPicker.quickSelect('BRAC University', 'Kha 224 Pragati Sarani, Merul Badda, Dhaka', 23.7781, 90.4265)">🎓 BRACU</button>
                            <button type="button" class="uber-pill-chip" onclick="LocationPicker.quickSelect('Mohammadpur', 'Mohammadpur, Dhaka-1207, Bangladesh', 23.7542, 90.3587)">Mohammadpur</button>
                            <button type="button" class="uber-pill-chip" onclick="LocationPicker.quickSelect('Mirpur 10', 'Mirpur 10 Circle, Begum Rokeya Sarani, Dhaka', 23.8069, 90.3687)">Mirpur 10</button>
                            <button type="button" class="uber-pill-chip" onclick="LocationPicker.quickSelect('Dhanmondi', 'Dhanmondi Residential Area, Dhaka-1205', 23.7465, 90.3760)">Dhanmondi</button>
                            <button type="button" class="uber-pill-chip" onclick="LocationPicker.quickSelect('Uttara', 'Uttara Model Town, Sector 3 / Rajlakshmi, Dhaka', 23.8759, 90.3795)">Uttara</button>
                            <button type="button" class="uber-pill-chip" onclick="LocationPicker.quickSelect('Gulshan 1', 'Gulshan 1 Circle, Gulshan Ave, Dhaka', 23.7785, 90.4172)">Gulshan 1</button>
                        </div>

                        <!-- Suggestions List Section -->
                        <div class="uber-suggestions-section">
                            <div class="uber-section-label" id="suggestionsHeader">POPULAR PLACES IN DHAKA</div>
                            <div id="pickerSuggestionsList" class="uber-suggestions-scroll-list"></div>
                        </div>

                        <!-- Selected Location Card & Confirm CTA -->
                        <div class="uber-selected-confirm-card">
                            <div class="uber-selected-info-row">
                                <div class="uber-pin-icon-square" id="uberCardBadge">📍</div>
                                <div class="uber-selected-texts">
                                    <div class="uber-selected-heading-row">
                                        <h4 id="pickerSelectedName" class="uber-selected-title">BRAC University</h4>
                                        <span id="pickerCoordsBadge" class="uber-coords-badge">23.7781, 90.4265</span>
                                    </div>
                                    <p id="pickerSelectedAddress" class="uber-selected-addr">Kha 224 Pragati Sarani, Merul Badda, Dhaka</p>
                                </div>
                            </div>
                            <button type="button" id="pickerConfirmBtn" class="uber-confirm-cta-btn">
                                <span id="confirmBtnText">Confirm Pickup Spot</span> ›
                            </button>
                        </div>

                    </div>

                    <!-- Right Column: Interactive Map View Canvas -->
                    <div class="uber-right-map-column">
                        <div id="uberLeafletMapCanvas" class="uber-map-canvas-element"></div>
                        
                        <!-- Floating Instruction Banner on Map -->
                        <div class="uber-map-floating-tip">
                            <span>📍 Tap anywhere on map or drag the pin marker</span>
                        </div>

                        <!-- Floating Map Action Buttons -->
                        <div class="uber-map-floating-controls">
                            <button type="button" class="uber-map-ctrl-btn" id="pickerRecenterBracuBtn" title="Center BRAC University">🎓</button>
                            <button type="button" class="uber-map-ctrl-btn" id="pickerZoomInBtn" title="Zoom In">+</button>
                            <button type="button" class="uber-map-ctrl-btn" id="pickerZoomOutBtn" title="Zoom Out">&minus;</button>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        `;

        document.body.insertAdjacentHTML('beforeend', html);
        modalEl = document.getElementById('uberLocationPickerModal');

        // Wire Listeners
        document.getElementById('pickerCloseBtn').addEventListener('click', close);
        document.getElementById('pickerConfirmBtn').addEventListener('click', confirmSelection);
        document.getElementById('pickerCurrentLocBtn').addEventListener('click', requestCurrentLocation);
        document.getElementById('pickerRecenterBracuBtn').addEventListener('click', function () {
            selectPlace('BRAC University', 'Kha 224 Pragati Sarani, Merul Badda, Dhaka', 23.7781, 90.4265);
        });

        document.getElementById('pickerZoomInBtn').addEventListener('click', function () {
            if (map) map.zoomIn();
        });
        document.getElementById('pickerZoomOutBtn').addEventListener('click', function () {
            if (map) map.zoomOut();
        });

        const searchInput = document.getElementById('pickerSearchInput');
        const clearBtn = document.getElementById('pickerClearSearchBtn');

        searchInput.addEventListener('input', function (e) {
            const val = e.target.value;
            clearBtn.style.display = val.length > 0 ? 'block' : 'none';
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                fetchSuggestions(val);
            }, 180);
        });

        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            clearBtn.style.display = 'none';
            fetchSuggestions('');
            searchInput.focus({ preventScroll: true });
        });

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modalEl && modalEl.classList.contains('active')) {
                close();
            }
        });
    }

    function createCustomMarkerIcon(name) {
        const pinColor = isDestinationMode ? '#10b981' : '#0284c7';
        return L.divIcon({
            className: 'uber-interactive-pin-container',
            html: `
                <div class="uber-marker-pin-wrap">
                    <div class="uber-marker-bubble">${escapeHTML(name || 'Selected Pin')}</div>
                    <div class="uber-marker-pin-svg">
                        <svg width="38" height="48" viewBox="0 0 38 48" fill="none">
                            <path d="M19 0C8.506 0 0 8.506 0 19C0 32.5 19 48 19 48C19 48 38 32.5 38 19C38 8.506 29.494 0 19 0Z" fill="${pinColor}"/>
                            <circle cx="19" cy="19" r="7" fill="white"/>
                        </svg>
                        <div class="uber-marker-pulse" style="background: ${pinColor};"></div>
                    </div>
                </div>
            `,
            iconSize: [38, 48],
            iconAnchor: [19, 48],
            popupAnchor: [0, -48]
        });
    }

    function initMap() {
        if (typeof L === 'undefined') {
            ensureLeafletLoaded(() => initMap());
            return;
        }

        const mapContainer = document.getElementById('uberLeafletMapCanvas');
        if (!mapContainer) return;

        if (map) {
            map.remove();
            map = null;
            marker = null;
        }

        map = L.map('uberLeafletMapCanvas', {
            center: [selectedPlace.lat, selectedPlace.lng],
            zoom: 16,
            zoomControl: false
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        // Add Draggable Pin Marker
        const pinIcon = createCustomMarkerIcon(selectedPlace.name);
        marker = L.marker([selectedPlace.lat, selectedPlace.lng], {
            icon: pinIcon,
            draggable: true,
            autoPan: true
        }).addTo(map);

        marker.on('dragstart', function () {
            document.getElementById('pickerSelectedName').innerText = 'Moving pin...';
        });

        marker.on('dragend', function (e) {
            const pos = marker.getLatLng();
            reverseGeocode(pos.lat, pos.lng);
        });

        // Click on map to drop / move pin
        map.on('click', function (e) {
            const lat = e.latlng.lat;
            const lng = e.latlng.lng;
            updatePinPosition(lat, lng, false);
            reverseGeocode(lat, lng);
        });

        isMapInitialized = true;
        invalidateMapSizes();
    }

    function updatePinPosition(lat, lng, pan = true) {
        if (!marker || !map) return;
        marker.setLatLng([lat, lng]);
        marker.setIcon(createCustomMarkerIcon(selectedPlace.name));
        if (pan) {
            map.panTo([lat, lng], { animate: true, duration: 0.35 });
        }
    }

    function invalidateMapSizes() {
        if (!map) return;
        map.invalidateSize();
        setTimeout(() => { if (map) map.invalidateSize(); }, 60);
        setTimeout(() => { if (map) map.invalidateSize(); }, 180);
        setTimeout(() => { if (map) map.invalidateSize(); }, 400);
    }

    function updateMapPosition(lat, lng, zoom = 16) {
        if (!map) return;
        map.setView([lat, lng], zoom, { animate: true });
        updatePinPosition(lat, lng, false);
        invalidateMapSizes();
    }

    function fetchSuggestions(query) {
        const listEl = document.getElementById('pickerSuggestionsList');
        const headerEl = document.getElementById('suggestionsHeader');

        if (!listEl) return;

        if (!query || query.trim().length === 0) {
            headerEl.innerText = 'POPULAR PLACES IN DHAKA';
        } else {
            headerEl.innerText = `SUGGESTIONS FOR "${query.toUpperCase()}"`;
        }

        fetch(`api_geocode.php?action=search&q=${encodeURIComponent(query)}`)
            .then(res => res.json())
            .then(data => {
                renderSuggestions(data);
            })
            .catch(err => {
                console.error("Geocoding error:", err);
            });
    }

    function renderSuggestions(places) {
        const listEl = document.getElementById('pickerSuggestionsList');
        if (!places || places.length === 0) {
            listEl.innerHTML = `
                <div class="uber-empty-suggestions-box">
                    <span>🔍</span> No places found. Tap directly on the map to drop a pin.
                </div>
            `;
            return;
        }

        listEl.innerHTML = places.map((p, idx) => {
            const isUni = p.type === 'university';
            const isTransit = p.type === 'transit';
            const icon = isUni ? '🎓' : (isTransit ? '🚌' : '📍');

            return `
            <div class="uber-suggestion-row-item" data-index="${idx}">
                <div class="uber-sugg-icon">${icon}</div>
                <div class="uber-sugg-details">
                    <div class="uber-sugg-name">${escapeHTML(p.name)}</div>
                    <div class="uber-sugg-sub">${escapeHTML(p.address || p.name)}</div>
                </div>
                <span class="uber-sugg-arrow">›</span>
            </div>
            `;
        }).join('');

        const items = listEl.querySelectorAll('.uber-suggestion-row-item');
        items.forEach(item => {
            item.addEventListener('click', function () {
                const idx = parseInt(this.getAttribute('data-index'));
                const place = places[idx];
                if (place) {
                    selectPlace(place.name, place.address, place.lat, place.lng);
                    document.getElementById('pickerSearchInput').value = place.name;
                }
            });
        });
    }

    function selectPlace(name, address, lat, lng) {
        selectedPlace = {
            name: name || 'Selected Location',
            address: address || name,
            lat: parseFloat(lat),
            lng: parseFloat(lng)
        };

        updateUIElements();
        updateMapPosition(selectedPlace.lat, selectedPlace.lng, 16);
    }

    function quickSelect(name, address, lat, lng) {
        document.getElementById('pickerSearchInput').value = name;
        selectPlace(name, address, lat, lng);
    }

    function updateUIElements() {
        document.getElementById('pickerSelectedName').innerText = selectedPlace.name;
        document.getElementById('pickerSelectedAddress').innerText = selectedPlace.address;
        document.getElementById('pickerCoordsBadge').innerText = `${selectedPlace.lat.toFixed(4)}, ${selectedPlace.lng.toFixed(4)}`;
        if (marker) {
            marker.setIcon(createCustomMarkerIcon(selectedPlace.name));
        }
    }

    function reverseGeocode(lat, lng) {
        document.getElementById('pickerSelectedName').innerText = 'Identifying location...';
        document.getElementById('pickerCoordsBadge').innerText = `${lat.toFixed(4)}, ${lng.toFixed(4)}`;

        fetch(`api_geocode.php?action=reverse&lat=${lat}&lng=${lng}`)
            .then(res => res.json())
            .then(data => {
                if (data && !data.error) {
                    selectedPlace = {
                        name: data.name || 'Selected Spot',
                        address: data.address || `${lat.toFixed(4)}, ${lng.toFixed(4)}`,
                        lat: lat,
                        lng: lng
                    };
                    updateUIElements();
                } else {
                    selectedPlace = {
                        name: 'Pinned Location',
                        address: `Coordinates: ${lat.toFixed(4)}, ${lng.toFixed(4)}`,
                        lat: lat,
                        lng: lng
                    };
                    updateUIElements();
                }
            })
            .catch(err => {
                selectedPlace = {
                    name: 'Pinned Location',
                    address: `Coordinates: ${lat.toFixed(4)}, ${lng.toFixed(4)}`,
                    lat: lat,
                    lng: lng
                };
                updateUIElements();
            });
    }

    function requestCurrentLocation() {
        const btn = document.getElementById('pickerCurrentLocBtn');
        if (!navigator.geolocation) {
            alert("Geolocation is not supported by your browser.");
            return;
        }

        btn.style.opacity = '0.5';
        navigator.geolocation.getCurrentPosition(
            function (position) {
                btn.style.opacity = '1';
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                updateMapPosition(lat, lng, 17);
                reverseGeocode(lat, lng);
            },
            function (err) {
                btn.style.opacity = '1';
                console.warn("Geolocation unavailable:", err.message);
                alert("GPS position unavailable. Tap anywhere on the map to pin your location.");
            },
            { enableHighAccuracy: true, timeout: 8000 }
        );
    }

    function open(options = {}) {
        createModalDOM();

        // Lock scroll & save position
        savedScrollY = window.scrollY || window.pageYOffset || document.documentElement.scrollTop;
        document.body.style.overflow = 'hidden';
        document.documentElement.style.overflow = 'hidden';

        currentCallback = options.onConfirm || null;
        isDestinationMode = options.isDestination || false;

        const titleText = isDestinationMode ? 'Where are you going?' : 'Where are you starting from?';
        const buttonActionText = isDestinationMode ? 'Confirm Destination' : 'Confirm Pickup Spot';
        const iconBadge = isDestinationMode ? '🎯' : '📍';

        document.getElementById('uberModalTitle').innerText = titleText;
        document.getElementById('confirmBtnText').innerText = buttonActionText;
        document.getElementById('pickerSearchInput').placeholder = titleText;
        document.getElementById('uberHeaderBadge').innerText = iconBadge;
        document.getElementById('uberCardBadge').innerText = iconBadge;
        document.getElementById('searchDotIcon').innerText = iconBadge;

        if (options.initialLocation && options.initialLocation.lat && options.initialLocation.lng) {
            selectedPlace = {
                name: options.initialLocation.name || 'Selected Spot',
                address: options.initialLocation.address || options.initialLocation.name,
                lat: parseFloat(options.initialLocation.lat),
                lng: parseFloat(options.initialLocation.lng)
            };
        }

        updateUIElements();
        document.getElementById('pickerSearchInput').value = '';
        document.getElementById('pickerClearSearchBtn').style.display = 'none';
        fetchSuggestions('');

        modalEl.style.display = 'flex';
        modalEl.classList.add('active');

        // Initialize Leaflet Map Stage
        ensureLeafletLoaded(() => {
            initMap();
            setTimeout(() => {
                if (map) {
                    updateMapPosition(selectedPlace.lat, selectedPlace.lng, 16);
                }
            }, 80);
        });
    }

    function close() {
        if (modalEl) {
            modalEl.classList.remove('active');
            modalEl.style.display = 'none';
        }
        document.body.style.overflow = '';
        document.documentElement.style.overflow = '';
        window.scrollTo(0, savedScrollY);
    }

    function confirmSelection() {
        if (typeof currentCallback === 'function') {
            currentCallback({ ...selectedPlace });
        }
        close();
    }

    function escapeHTML(str) {
        if (!str) return '';
        return str.replace(/[&<>'"]/g, tag => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        }[tag] || tag));
    }

    return {
        open: open,
        close: close,
        selectPlace: selectPlace,
        quickSelect: quickSelect
    };
})();

// Reusable Helper to bind LocationPicker to Form Elements
function attachUberLocationPicker(options) {
    const triggerEl = document.getElementById(options.triggerId);
    const displayInput = document.getElementById(options.displayInputId);
    const hiddenLat = document.getElementById(options.hiddenLatId);
    const hiddenLng = document.getElementById(options.hiddenLngId);
    const hiddenAddr = options.hiddenAddrId ? document.getElementById(options.hiddenAddrId) : null;

    if (!triggerEl && !displayInput) return;

    function handleOpen(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        const curName = displayInput ? displayInput.value : '';
        const curLat = hiddenLat ? hiddenLat.value : null;
        const curLng = hiddenLng ? hiddenLng.value : null;

        LocationPicker.open({
            title: options.title || 'Choose Location',
            isDestination: options.isDestination || false,
            initialLocation: {
                name: curName,
                address: hiddenAddr ? hiddenAddr.value : curName,
                lat: curLat,
                lng: curLng
            },
            onConfirm: function (loc) {
                if (displayInput) {
                    displayInput.value = loc.name;
                }
                if (hiddenLat) hiddenLat.value = loc.lat;
                if (hiddenLng) hiddenLng.value = loc.lng;
                if (hiddenAddr) hiddenAddr.value = loc.address;

                if (typeof options.onChange === 'function') {
                    options.onChange(loc);
                }
            }
        });
    }

    if (triggerEl) {
        triggerEl.addEventListener('click', handleOpen);
    }
    if (displayInput) {
        displayInput.addEventListener('click', handleOpen);
        displayInput.addEventListener('focus', function(e) {
            e.preventDefault();
            this.blur();
            handleOpen(e);
        });
    }
}
