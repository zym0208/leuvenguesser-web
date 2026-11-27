(function () {
    const config = window.StarGlobeConfig || {};
    const playedLocations = Array.isArray(config.markerLocations)
        ? config.markerLocations.filter((location) => (
            Number.isFinite(Number(location.lat))
            && Number.isFinite(Number(location.lon))
            && location.name
        )).map((location) => ({
            id: location.id,
            name: String(location.name),
            lat: Number(location.lat),
            lon: Number(location.lon)
        }))
        : [];

    const mobileMenuToggle = document.querySelector("[data-mobile-menu-toggle]");
    const mobileMenu = document.querySelector("[data-mobile-menu]");
    const modeModal = document.querySelector("[data-mode-modal]");
    const openModeButtons = document.querySelectorAll("[data-open-mode-modal]");
    const closeModeButtons = document.querySelectorAll("[data-close-mode-modal]");
    const rankingsModal = document.querySelector("[data-rankings-modal]");
    const rankingsListEl = document.querySelector("[data-rankings-list]");
    const rankingYou = document.getElementById("ranking-you");
    const openRankingsButtons = document.querySelectorAll("[data-open-rankings-modal]");
    const closeRankingsButtons = document.querySelectorAll("[data-close-rankings-modal]");
    const rankingsTabButtons = document.querySelectorAll("[data-rankings-tab]");
    const publishModal = document.querySelector("[data-publish-modal]");
    const publishForm = document.querySelector("[data-publish-form]");
    const openPublishButtons = document.querySelectorAll("[data-open-publish-modal]");
    const closePublishButtons = document.querySelectorAll("[data-close-publish-modal]");
    const publishCountSelect = document.querySelector("[data-publish-count]");
    const publishFilePicker = document.querySelector("[data-publish-file-picker]");
    const publishPreview = document.querySelector("[data-publish-preview]");
    const publishMapEl = document.querySelector("[data-publish-map]");
    const publishLatInput = document.querySelector("[data-publish-lat]");
    const publishLngInput = document.querySelector("[data-publish-lng]");
    const publishRegionName = document.querySelector("[data-publish-region-name]");
    const publishAuthorInput = document.querySelector("[data-publish-author]");
    const publishRegionId = document.querySelector("[data-publish-region-id]");
    const publishRegionLat = document.querySelector("[data-publish-region-lat]");
    const publishRegionLng = document.querySelector("[data-publish-region-lng]");
    const publishRegionSwLat = document.querySelector("[data-publish-region-sw-lat]");
    const publishRegionSwLng = document.querySelector("[data-publish-region-sw-lng]");
    const publishRegionNeLat = document.querySelector("[data-publish-region-ne-lat]");
    const publishRegionNeLng = document.querySelector("[data-publish-region-ne-lng]");
    const publishPhotoList = document.querySelector("[data-publish-photo-list]");
    const publishStatus = document.querySelector("[data-publish-status]");
    const publishSubmit = document.querySelector("[data-publish-submit]");
    const panel = document.querySelector("[data-side-panel]");
    const panelToggle = document.querySelector("[data-panel-toggle]");
    const panelReopen = document.querySelector("[data-panel-reopen]");
    const panelTitle = document.querySelector("[data-panel-title]");
    const tabButtons = document.querySelectorAll("[data-tab-trigger]");
    const tabPanels = document.querySelectorAll("[data-tab-panel]");
    const markerOverlay = document.querySelector("[data-marker-overlay]");
    const tokenWarning = document.querySelector("[data-token-warning]");
    const globeContainer = document.getElementById("cesium-globe");
    const authModal = document.querySelector("[data-auth-modal]");
    const openAuthButtons = document.querySelectorAll("[data-open-auth-modal]");
    const closeAuthButtons = document.querySelectorAll("[data-close-auth-modal]");
    const loginForm = document.querySelector("[data-login-form]");
    const signupForm = document.querySelector("[data-signup-form]");
    const loginError = document.querySelector("[data-login-error]");
    const signupError = document.querySelector("[data-signup-error]");
    const toSignupBtn = document.querySelector("[data-to-signup]");
    const toLoginBtn = document.querySelector("[data-to-login]");
    const loginBtn = document.querySelector("[data-login-btn]");
    const userDisplay = document.querySelector("[data-user-display]");
    const userNameEl = document.querySelector("[data-user-name]");
    const logoutBtn = document.querySelector("[data-logout]");
    const authTitle = document.querySelector("[data-auth-title]");
    const authSubtitle = document.querySelector("[data-auth-subtitle]");
    const duelMenus = document.querySelectorAll(".duel-menu");
    const duelStatus = document.querySelectorAll(".duel-status");
    const friendsNavButtons = document.querySelectorAll(".friends-nav-link");
    const adminTopbarButtons = document.querySelectorAll("[data-admin-topbar]");

    const USER_KEY = "lg_user";
    const MAX_CHALLENGE_SET_DIAGONAL_METERS = 30000;
    const SPREAD_ERROR = "Images are too spread out. Split this into multiple challenge sets.";
    const apiUrl = (path) => `${window.Leuvenguesser?.basePath || ""}${path}`;

    let viewer;
    let postRenderCallback;
    let resizeCallback;
    let recomputeQueued = false;
    let publishMap;
    let publishMarker;
    let publishPreviewUrl;
    let publishSelectedIndex = 0;
    let publishFilesBySlot = [];
    let publishPoints = [];
    let publishRegionResolving = false;
    const markerButtons = new Map();

    const panelNavButtons = document.querySelectorAll("[data-panel-nav]");
    const panelSections = document.querySelectorAll("[data-panel-section]");
    const modesList = document.querySelector("[data-modes-list]");
    const modeDetailEl = document.querySelector("[data-mode-detail]");
    const modeDetailBody = document.querySelector("[data-mode-detail-body]");
    const regionsList = document.querySelector("[data-regions-list]");
    const regionDetailEl = document.querySelector("[data-region-detail]");
    const regionDetailBody = document.querySelector("[data-region-detail-body]");

    let duelLink = null;

    function toggleHidden(element, hidden) {
        if (element) {
            element.hidden = hidden;
        }
    }

    function setActiveTab(nextTab) {
        tabButtons.forEach((button) => {
            button.classList.toggle("is-active", button.dataset.tabTrigger === nextTab);
        });

        tabPanels.forEach((panelNode) => {
            panelNode.classList.toggle("is-active", panelNode.dataset.tabPanel === nextTab);
        });

        if (panelTitle && panelTitles[nextTab]) {
            panelTitle.textContent = panelTitles[nextTab];
        }
    }

    function openModal() {
        toggleHidden(modeModal, false);
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        toggleHidden(modeModal, true);
        document.body.style.overflow = "";
    }

    function openRankingsModal() {
        if (!requireLoggedIn("Log in to view rankings.")) {
            return;
        }

        toggleHidden(rankingsModal, false);
        document.body.style.overflow = "hidden";
        setActiveRankingsTab("leuven");
    }

    function setActiveRankingsTab(mode) {
        rankingsTabButtons.forEach((btn) => {
            btn.classList.toggle("is-active", btn.dataset.rankingsTab === mode);
        });
        loadRankings(mode);
    }

    function closeRankingsModal() {
        toggleHidden(rankingsModal, true);
        document.body.style.overflow = "";
    }

    function openPublishModal() {
        if (!requireLoggedIn("Log in to publish a challenge.")) {
            return;
        }

        syncPublishAuthor();
        toggleHidden(publishModal, false);
        document.body.style.overflow = "hidden";
        initPublishMap();
    }

    function closePublishModal() {
        toggleHidden(publishModal, true);
        document.body.style.overflow = "";
    }

    function setPublishStatus(message, isError) {
        if (!publishStatus) {
            return;
        }

        publishStatus.textContent = message;
        publishStatus.classList.toggle("is-error", Boolean(isError));
    }

    function setPublishPoint(lat, lng) {
        if (!publishLatInput || !publishLngInput) {
            return;
        }

        if (publishFilesBySlot.length === 0) {
            setPublishStatus("Choose image count first.", true);
            return;
        }

        if (!publishFilesBySlot[publishSelectedIndex]) {
            setPublishStatus(`Upload image ${publishSelectedIndex + 1} before choosing its answer point.`, true);
            return;
        }

        if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
            setPublishStatus("Choose a valid latitude and longitude.", true);
            return;
        }

        setPublishStatus("", false);
        publishLatInput.value = lat.toFixed(6);
        publishLngInput.value = lng.toFixed(6);
        publishPoints[publishSelectedIndex] = { lat, lng };
        renderPublishPhotoList();
        resolvePublishRegionSet();

        if (publishMap && window.L) {
            const latLng = window.L.latLng(lat, lng);
            if (publishMarker) {
                publishMarker.setLatLng(latLng);
            } else {
                publishMarker = window.L.marker(latLng).addTo(publishMap);
            }
        }
    }

    function initPublishMap() {
        if (publishMap || !publishMapEl || !window.L) {
            if (publishMap) {
                window.setTimeout(() => publishMap.invalidateSize(), 0);
            }
            return;
        }

        const worldBounds = window.L.latLngBounds([-85, -180], [85, 180]);
        publishMap = window.L.map(publishMapEl, {
            minZoom: 2,
            maxZoom: 19,
            maxBounds: worldBounds,
            maxBoundsViscosity: 1.0
        }).setView([20, 0], 2);

        window.L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution: "&copy; OpenStreetMap contributors"
        }).addTo(publishMap);

        publishMap.on("click", (event) => {
            setPublishPoint(event.latlng.lat, event.latlng.lng);
        });

        window.setTimeout(() => publishMap.invalidateSize(), 0);
    }

    function clearPublishMarker() {
        if (publishMarker && publishMap) {
            publishMap.removeLayer(publishMarker);
            publishMarker = null;
        }
    }

    function updatePublishPreview() {
        if (!publishPreview) {
            return;
        }

        if (publishPreviewUrl) {
            URL.revokeObjectURL(publishPreviewUrl);
            publishPreviewUrl = null;
        }

        if (publishFilesBySlot.length === 0) {
            publishPreview.innerHTML = '<button class="publish-preview-upload" type="button" disabled><span>Choose how many images to upload</span></button>';
            return;
        }

        const file = publishFilesBySlot[publishSelectedIndex];
        const point = publishPoints[publishSelectedIndex];
        const canGoPrev = publishSelectedIndex > 0;
        const canGoNext = publishSelectedIndex < publishFilesBySlot.length - 1;
        const pointText = point
            ? `${point.lat.toFixed(6)}, ${point.lng.toFixed(6)}`
            : "No answer point yet - click the map to set it.";

        if (!file) {
            publishPreview.innerHTML = `
                <button class="publish-preview-upload" type="button" data-publish-upload-current>
                    <span>Click to upload image</span>
                    <small>${pointText}</small>
                </button>
                <button class="publish-preview-nav prev" type="button" data-publish-prev ${canGoPrev ? "" : "disabled"} aria-label="Previous image">&lt;</button>
                <button class="publish-preview-nav next" type="button" data-publish-next ${canGoNext ? "" : "disabled"} aria-label="Next image">&gt;</button>`;
            bindPublishPreviewActions();
            return;
        }

        publishPreviewUrl = URL.createObjectURL(file);
        publishPreview.innerHTML = `
            <button class="publish-preview-delete" type="button" data-publish-delete-current aria-label="Remove current image">x</button>
            <button class="publish-preview-nav prev" type="button" data-publish-prev ${canGoPrev ? "" : "disabled"} aria-label="Previous image">&lt;</button>
            <button class="publish-preview-nav next" type="button" data-publish-next ${canGoNext ? "" : "disabled"} aria-label="Next image">&gt;</button>
            <div class="publish-preview-meta"><small>${escapeHtml(pointText)}</small></div>`;
        const image = document.createElement("img");
        image.src = publishPreviewUrl;
        image.alt = "Challenge preview";
        publishPreview.appendChild(image);
        bindPublishPreviewActions();
    }

    function bindPublishPreviewActions() {
        publishPreview.querySelector("[data-publish-upload-current]")?.addEventListener("click", () => {
            publishFilePicker?.click();
        });
        publishPreview.querySelector("[data-publish-delete-current]")?.addEventListener("click", (event) => {
            event.stopPropagation();
            removePublishImage(publishSelectedIndex);
        });
        publishPreview.querySelector("[data-publish-prev]")?.addEventListener("click", (event) => {
            event.stopPropagation();
            selectPublishImage(Math.max(0, publishSelectedIndex - 1));
        });
        publishPreview.querySelector("[data-publish-next]")?.addEventListener("click", (event) => {
            event.stopPropagation();
            selectPublishImage(Math.min(publishFilesBySlot.length - 1, publishSelectedIndex + 1));
        });
    }

    function removePublishImage(index) {
        if (!Object.prototype.hasOwnProperty.call(publishFilesBySlot, index)) {
            return;
        }

        publishFilesBySlot[index] = null;
        publishPoints[index] = null;
        if (publishFilePicker) {
            publishFilePicker.value = "";
        }
        setPublishStatus(`Image ${index + 1} removed.`, false);
        resolvePublishRegionSet();
        selectPublishImage(index);
    }

    function publishFiles() {
        return publishFilesBySlot.slice();
    }

    function setPublishSlotCount(count) {
        setPublishStatus("", false);
        const slotCount = Number.isInteger(count) && count > 0 ? Math.min(count, 10) : 0;
        if (publishCountSelect && Number(publishCountSelect.value) !== slotCount) {
            publishCountSelect.value = slotCount > 0 ? String(slotCount) : "";
        }
        const previousFiles = publishFilesBySlot.slice();
        const previousPoints = publishPoints.slice();

        publishFilesBySlot = Array.from({ length: slotCount }, (_, index) => previousFiles[index] || null);
        publishPoints = Array.from({ length: slotCount }, (_, index) => previousPoints[index] || null);
        publishSelectedIndex = slotCount > 0 ? Math.min(publishSelectedIndex, slotCount - 1) : 0;

        if (slotCount === 0) {
            if (publishLatInput) {
                publishLatInput.value = "";
            }
            if (publishLngInput) {
                publishLngInput.value = "";
            }
            clearPublishMarker();
            renderPublishPhotoList();
            updatePublishPreview();
            resolvePublishRegionSet();
            return;
        }

        selectPublishImage(publishSelectedIndex);
        resolvePublishRegionSet();
    }

    function resetPublishWorkflow() {
        publishFilesBySlot = [];
        publishPoints = [];
        publishSelectedIndex = 0;
        if (publishLatInput) {
            publishLatInput.value = "";
        }
        if (publishLngInput) {
            publishLngInput.value = "";
        }
        [publishRegionId, publishRegionLat, publishRegionLng, publishRegionSwLat, publishRegionSwLng, publishRegionNeLat, publishRegionNeLng].forEach((input) => {
            if (input) {
                input.value = "";
            }
        });
        if (publishRegionName) {
            publishRegionName.value = "";
        }
        clearPublishMarker();
        renderPublishPhotoList();
        updatePublishPreview();
    }

    function selectPublishImage(index) {
        if (!publishFilesBySlot[index] && !Object.prototype.hasOwnProperty.call(publishFilesBySlot, index)) {
            return;
        }

        publishSelectedIndex = index;
        updatePublishPreview();

        const point = publishPoints[index];
        if (point) {
            if (publishLatInput) {
                publishLatInput.value = point.lat.toFixed(6);
            }
            if (publishLngInput) {
                publishLngInput.value = point.lng.toFixed(6);
            }
            if (publishMap && window.L) {
                const latLng = window.L.latLng(point.lat, point.lng);
                if (publishMarker) {
                    publishMarker.setLatLng(latLng);
                } else {
                    publishMarker = window.L.marker(latLng).addTo(publishMap);
                }
            }
        } else {
            if (publishLatInput) {
                publishLatInput.value = "";
            }
            if (publishLngInput) {
                publishLngInput.value = "";
            }
            clearPublishMarker();
        }

        renderPublishPhotoList();
    }

    function renderPublishPhotoList() {
        if (!publishPhotoList) {
            return;
        }

        const files = publishFiles();
        publishPhotoList.innerHTML = files.map((file, index) => {
            const point = publishPoints[index];
            const state = point ? `${point.lat.toFixed(4)}, ${point.lng.toFixed(4)}` : "No point";
            const label = file ? file.name : "Missing file";
            return `<button class="publish-photo-item${index === publishSelectedIndex ? " is-active" : ""}" type="button" data-publish-photo-index="${index}">
                <span>${index + 1}. ${escapeHtml(label)}</span>
                <strong>${state}</strong>
            </button>`;
        }).join("");

        publishPhotoList.querySelectorAll("[data-publish-photo-index]").forEach((button) => {
            button.addEventListener("click", () => selectPublishImage(Number(button.dataset.publishPhotoIndex)));
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function clampCoordinate(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function buildRegionPayload(points, fallbackName) {
        const validPoints = points.filter((point) => point && Number.isFinite(point.lat) && Number.isFinite(point.lng));
        if (validPoints.length === 0) {
            return null;
        }

        const lats = validPoints.map((point) => point.lat);
        const lngs = validPoints.map((point) => point.lng);
        const minLat = Math.min(...lats);
        const maxLat = Math.max(...lats);
        const minLng = Math.min(...lngs);
        const maxLng = Math.max(...lngs);
        const centerLat = lats.reduce((sum, lat) => sum + lat, 0) / lats.length;
        const centerLng = lngs.reduce((sum, lng) => sum + lng, 0) / lngs.length;
        const latSpan = Math.max(0.02, maxLat - minLat);
        const lngSpan = Math.max(0.02, maxLng - minLng);
        const latPadding = Math.max(0.01, latSpan * 0.15);
        const lngPadding = Math.max(0.01, lngSpan * 0.15);
        const name = (fallbackName || "").trim() || `${centerLat.toFixed(4)}, ${centerLng.toFixed(4)}`;

        return {
            name,
            centerLat: clampCoordinate(centerLat, -85, 85).toFixed(6),
            centerLng: clampCoordinate(centerLng, -180, 180).toFixed(6),
            swLat: clampCoordinate(minLat - latPadding, -85, 85).toFixed(6),
            swLng: clampCoordinate(minLng - lngPadding, -180, 180).toFixed(6),
            neLat: clampCoordinate(maxLat + latPadding, -85, 85).toFixed(6),
            neLng: clampCoordinate(maxLng + lngPadding, -180, 180).toFixed(6)
        };
    }

    function distanceMeters(left, right) {
        const earthRadius = 6371000;
        const toRad = (value) => value * Math.PI / 180;
        const dLat = toRad(right.lat - left.lat);
        const dLng = toRad(right.lng - left.lng);
        const a = Math.sin(dLat / 2) ** 2
            + Math.cos(toRad(left.lat)) * Math.cos(toRad(right.lat)) * Math.sin(dLng / 2) ** 2;

        return 2 * earthRadius * Math.asin(Math.min(1, Math.sqrt(a)));
    }

    function assertSpreadAllowed(points) {
        const validPoints = points.filter((point) => point && Number.isFinite(point.lat) && Number.isFinite(point.lng));
        for (let i = 0; i < validPoints.length; i += 1) {
            for (let j = i + 1; j < validPoints.length; j += 1) {
                if (distanceMeters(validPoints[i], validPoints[j]) > MAX_CHALLENGE_SET_DIAGONAL_METERS) {
                    throw new Error(SPREAD_ERROR);
                }
            }
        }
    }

    async function submitPublishForm(event) {
        event.preventDefault();

        if (!publishForm || !publishSubmit) {
            return;
        }

        const user = getStoredUser();
        if (!user || !user.name) {
            setPublishStatus("Log in to publish a challenge.", true);
            openAuthModal(false);
            return;
        }

        syncPublishAuthor();
        setPublishStatus("Publishing...", false);
        publishSubmit.disabled = true;

        try {
            if (publishRegionResolving || (publishRegionName && publishRegionName.value === "Resolving region...")) {
                throw new Error("Wait for the region name to finish resolving.");
            }

            const title = publishForm.elements.title.value.trim();
            if (!title) {
                throw new Error("Enter a challenge set title.");
            }

            const selectedCount = publishFilesBySlot.length;
            if (selectedCount === 0) {
                throw new Error("Choose how many images you want to upload.");
            }

            const missingFileIndex = publishFilesBySlot.findIndex((file) => !file);
            if (missingFileIndex !== -1) {
                throw new Error(`Upload image ${missingFileIndex + 1}.`);
            }

            const missingPointIndex = publishPoints.findIndex((point) => !point);
            if (missingPointIndex !== -1) {
                throw new Error(`Choose the answer point for image ${missingPointIndex + 1}.`);
            }

            assertSpreadAllowed(publishPoints);
            const regionPayload = buildRegionPayload(publishPoints, title);
            if (!regionPayload || !publishRegionName || publishRegionName.value.trim() === "") {
                throw new Error("Choose answer points so the region can be generated.");
            }

            const formData = new FormData();
            const files = await compressFilesForUpload(publishFiles());
            files.forEach((file) => formData.append("images[]", file));
            publishPoints.forEach((point) => {
                formData.append("lats[]", point ? point.lat : "");
                formData.append("lngs[]", point ? point.lng : "");
            });
            formData.append("title", title);
            formData.append("description", publishForm.elements.description.value.trim());
            formData.append("authorName", user.name);
            if (user.id) {
                formData.append("authorUserId", user.id);
                formData.append("userId", user.id);
            }
            formData.append("userName", user.name);
            formData.append("regionId", "");
            formData.append("regionName", publishRegionName.value.trim());
            formData.append("regionLat", regionPayload.centerLat);
            formData.append("regionLng", regionPayload.centerLng);
            formData.append("regionSwLat", regionPayload.swLat);
            formData.append("regionSwLng", regionPayload.swLng);
            formData.append("regionNeLat", regionPayload.neLat);
            formData.append("regionNeLng", regionPayload.neLng);

            const response = await fetch(apiUrl("/api/challenges"), {
                method: "POST",
                body: formData,
                cache: "no-store"
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok || !data.success) {
                const detail = data.details || data.error || "Publish failed";
                throw new Error(typeof detail === "string" ? detail : "Publish failed");
            }

            setPublishStatus("Published. The round is now in the challenge pool.", false);
            publishForm.reset();
            resetPublishWorkflow();
            syncPublishAuthor();
        } catch (error) {
            setPublishStatus(error.message || "Publish failed", true);
        } finally {
            publishSubmit.disabled = false;
        }
    }

    async function resolvePublishRegion(lat, lng, boundsOverride) {
        if (!publishRegionName) {
            return;
        }

        publishRegionName.value = "Resolving region...";
        publishRegionResolving = true;
        if (publishRegionId) {
            publishRegionId.value = "";
        }
        if (publishRegionLat) {
            publishRegionLat.value = String(lat);
        }
        if (publishRegionLng) {
            publishRegionLng.value = String(lng);
        }

        let name = `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
        let bounds = boundsOverride || null;

        try {
            const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=10&addressdetails=1&namedetails=1&accept-language=en`;
            const geoRes = await fetch(url, {
                headers: { "Accept": "application/json", "Accept-Language": "en" },
                cache: "no-store"
            });
            const geo = await geoRes.json().catch(() => ({}));
            if (geo && geo.namedetails && geo.namedetails["name:en"]) {
                name = geo.namedetails["name:en"];
            } else if (geo && geo.address) {
                name = geo.address.city || geo.address.town || geo.address.village || geo.address.county || geo.address.state || geo.address.country || geo.display_name || name;
            } else if (geo && geo.display_name) {
                name = geo.display_name;
            }
            if (!bounds && Array.isArray(geo.boundingbox) && geo.boundingbox.length === 4) {
                bounds = {
                    swLat: Number(geo.boundingbox[0]),
                    neLat: Number(geo.boundingbox[1]),
                    swLng: Number(geo.boundingbox[2]),
                    neLng: Number(geo.boundingbox[3])
                };
            }
        } catch {
            // Keep coordinate fallback.
        }

        let data = {};
        try {
            const apiRes = await fetch(apiUrl("/api/challenges/regions/resolve"), {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ lat, lng, name, bounds }),
                cache: "no-store"
            });
            data = await apiRes.json().catch(() => ({}));
            if (!apiRes.ok || !data.success || !data.region) {
                throw new Error("Region resolve failed.");
            }
        } catch {
            publishRegionName.value = name;
            publishRegionResolving = false;
            return;
        }

        if (publishRegionId) {
            publishRegionId.value = data.region.id || "";
        }
        publishRegionName.value = data.region.name;
        if (publishRegionLat) {
            publishRegionLat.value = data.region.center_lat;
        }
        if (publishRegionLng) {
            publishRegionLng.value = data.region.center_lng;
        }
        if (publishRegionSwLat) {
            publishRegionSwLat.value = data.region.sw_lat;
        }
        if (publishRegionSwLng) {
            publishRegionSwLng.value = data.region.sw_lng;
        }
        if (publishRegionNeLat) {
            publishRegionNeLat.value = data.region.ne_lat;
        }
        if (publishRegionNeLng) {
            publishRegionNeLng.value = data.region.ne_lng;
        }
        publishRegionResolving = false;
    }

    async function resolvePublishRegionSet() {
        if (!publishRegionName) {
            return;
        }

        const points = publishPoints.filter((point) => point && Number.isFinite(point.lat) && Number.isFinite(point.lng));
        publishRegionName.value = "";
        if (publishRegionId) {
            publishRegionId.value = "";
        }

        if (points.length === 0) {
            return;
        }

        try {
            assertSpreadAllowed(points);
        } catch (error) {
            setPublishStatus(error.message || SPREAD_ERROR, true);
            return;
        }

        const regionPayload = buildRegionPayload(points, "");
        if (!regionPayload) {
            return;
        }

        await resolvePublishRegion(Number(regionPayload.centerLat), Number(regionPayload.centerLng), {
            swLat: Number(regionPayload.swLat),
            swLng: Number(regionPayload.swLng),
            neLat: Number(regionPayload.neLat),
            neLng: Number(regionPayload.neLng)
        });
    }

    async function compressFilesForUpload(files) {
        const limit = 1.8 * 1024 * 1024;
        const compressed = [];

        for (const file of files) {
            if (file.size <= limit) {
                compressed.push(file);
                continue;
            }

            const resized = await compressImage(file, limit);
            compressed.push(resized);
        }

        return compressed;
    }

    function compressImage(file, limit) {
        return new Promise((resolve, reject) => {
            const image = new Image();
            const objectUrl = URL.createObjectURL(file);

            image.onload = () => {
                URL.revokeObjectURL(objectUrl);
                const canvas = document.createElement("canvas");
                const maxSide = 1600;
                const scale = Math.min(1, maxSide / Math.max(image.width, image.height));
                canvas.width = Math.max(1, Math.round(image.width * scale));
                canvas.height = Math.max(1, Math.round(image.height * scale));

                const ctx = canvas.getContext("2d");
                ctx.drawImage(image, 0, 0, canvas.width, canvas.height);

                let quality = 0.86;
                const makeBlob = () => {
                    canvas.toBlob((blob) => {
                        if (!blob) {
                            reject(new Error("Unable to compress image."));
                            return;
                        }
                        if (blob.size > limit && quality > 0.55) {
                            quality -= 0.08;
                            makeBlob();
                            return;
                        }

                        const name = file.name.replace(/\.[^.]+$/, "") + ".jpg";
                        resolve(new File([blob], name, { type: "image/jpeg" }));
                    }, "image/jpeg", quality);
                };

                makeBlob();
            };

            image.onerror = () => {
                URL.revokeObjectURL(objectUrl);
                reject(new Error("Unable to read image for compression."));
            };

            image.src = objectUrl;
        });
    }

    function getStoredUser() {
        try {
            const raw = localStorage.getItem(USER_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    function saveUser(user) {
        try {
            localStorage.setItem(USER_KEY, JSON.stringify(user));
            enableIfLoggedIn();
        } catch {
            // ignore
        }
    }

    function clearStoredUser() {
        localStorage.removeItem(USER_KEY);
    }

    function applyUserState() {
        const user = getStoredUser();
        if (user && user.name) {
            toggleHidden(loginBtn, true);
            toggleHidden(userDisplay, false);
            if (userNameEl) {
                userNameEl.textContent = user.name;
            }
        } else {
            toggleHidden(loginBtn, false);
            toggleHidden(userDisplay, true);
        }
        syncPublishAuthor();
    }

    function syncPublishAuthor() {
        if (!publishAuthorInput) {
            return;
        }

        const user = getStoredUser();
        publishAuthorInput.value = user && user.name ? user.name : "";
        publishAuthorInput.placeholder = user && user.name ? "Author" : "Log in to publish";
    }

    function requireLoggedIn(message) {
        const user = getStoredUser();
        if (user && user.id) {
            return true;
        }

        if (message) {
            window.alert(message);
        }
        openAuthModal(false);
        return false;
    }

    function openAuthModal(showSignup) {
        toggleHidden(authModal, false);
        document.body.style.overflow = "hidden";
        showAuthView(showSignup ? "signup" : "login");
    }

    function closeAuthModal() {
        toggleHidden(authModal, true);
        document.body.style.overflow = "";
        clearAuthErrors();
    }

    function showAuthView(view) {
        const isLogin = view === "login";
        toggleHidden(loginForm, !isLogin);
        toggleHidden(signupForm, isLogin);
        if (authTitle) {
            authTitle.textContent = isLogin ? "Log In" : "Create Account";
        }
        if (authSubtitle) {
            authSubtitle.textContent = isLogin
                ? "Welcome back — enter your details below."
                : "Join Leuvenguesser and track your scores.";
        }
        clearAuthErrors();
    }

    function clearAuthErrors() {
        if (loginError) {
            loginError.textContent = "";
        }
        if (signupError) {
            signupError.textContent = "";
        }
    }

    function authErrorMessage(data, res, fallback) {
        if (data && typeof data.error === "string" && data.error !== "") {
            if (typeof data.details === "string" && data.details !== "") {
                return `${data.error} (${data.details})`;
            }
            return data.error;
        }
        const contentType = res.headers.get("content-type") || "";
        if (!contentType.includes("application/json")) {
            return "Server returned an unexpected response. Start the app with: php -S localhost:8000 router.php";
        }
        return fallback;
    }

    async function submitLogin(event) {
        event.preventDefault();
        if (!loginForm) {
            return;
        }
        const name = loginForm.elements.name.value.trim();
        const password = loginForm.elements.password.value;
        if (!name || !password) {
            if (loginError) {
                loginError.textContent = "Enter your username and password.";
            }
            return;
        }
        const submit = loginForm.querySelector("[type=submit]");
        if (submit) {
            submit.disabled = true;
        }
        if (loginError) {
            loginError.textContent = "";
        }
        try {
            const res = await fetch(apiUrl("/api/auth/login"), {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ name, password }),
                cache: "no-store"
            });
            const data = await parseJsonResponse(res);
            if (!res.ok || !data.success) {
                if (loginError) {
                    loginError.textContent = authErrorMessage(data, res, "Login failed. Check your credentials.");
                }
                return;
            }
            saveUser(data.user);
            applyUserState();
            closeAuthModal();
            if (window.FriendsNav && typeof window.FriendsNav.refreshBadge === "function") {
                window.FriendsNav.refreshBadge();
            }
        } catch {
            if (loginError) {
                loginError.textContent = "Network error. Please try again.";
            }
        } finally {
            enableIfLoggedIn();
            if (submit) {
                submit.disabled = false;
            }
        }
    }

    async function submitSignup(event) {
        event.preventDefault();
        if (!signupForm) {
            return;
        }
        const name = signupForm.elements.name.value.trim();
        const password = signupForm.elements.password.value;
        if (!name || !password) {
            if (signupError) {
                signupError.textContent = "Enter a username and password.";
            }
            return;
        }
        const submit = signupForm.querySelector("[type=submit]");
        if (submit) {
            submit.disabled = true;
        }
        if (signupError) {
            signupError.textContent = "";
        }
        try {
            const res = await fetch(apiUrl("/api/auth/signup"), {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ name, password }),
                cache: "no-store"
            });
            const data = await parseJsonResponse(res);
            if (!res.ok || !data.success) {
                if (signupError) {
                    signupError.textContent = authErrorMessage(data, res, "Sign up failed. Try a different username.");
                }
                return;
            }
            saveUser(data.user);
            applyUserState();
            closeAuthModal();
            if (window.FriendsNav && typeof window.FriendsNav.refreshBadge === "function") {
                window.FriendsNav.refreshBadge();
            }
        } catch {
            if (signupError) {
                signupError.textContent = "Network error. Please try again.";
            }
        } finally {
            enableIfLoggedIn();
            if (submit) {
                submit.disabled = false;
            }
        }
    }

    async function loadRankings(mode) {
        if (!rankingsListEl) {
            return;
        }

        mode = mode || 'leuven';
        rankingsListEl.innerHTML = '<div class="rankings-loading">Loading…</div>';
        if (rankingYou) { rankingYou.innerHTML = ''; }

        try {
            const baseUrl = (window.APP_API && window.APP_API.urls && window.APP_API.urls['/api/game/rankings'])
                || apiUrl('/api/game/rankings');
            const url = `${baseUrl}?mode=${encodeURIComponent(mode)}`;
        const res = await fetch(url, { cache: 'no-store' });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success || !Array.isArray(data.rankings)) {
                throw new Error('Failed to load rankings');
            }
            if (data.rankings.length === 0) {
                rankingsListEl.innerHTML = '<div class="rankings-empty">No players yet — be the first!</div>';
                return;
            }
            const medals = ['🥇', '🥈', '🥉'];
            rankingsListEl.innerHTML = data.rankings.map((entry) => {
                const safeName = entry.name.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                const badge = medals[entry.rank - 1] || String(entry.rank);
                return `<div class="rankings-item${entry.rank <= 3 ? ' is-top' : ''}">
                    <span class="rankings-rank">${badge}</span>
                    <span class="rankings-name">${safeName}</span>
                    <span class="rankings-score">${entry.score.toLocaleString()}</span>
                </div>`;
            }).join('');

            const user = getStoredUser();
            if (!user || !user.id) {
                rankingYou.innerHTML = '<div class="ranking-empty">Rankings are available after login.</div>';
            }
            else{
                try{
                    var isUser = false;
                    var rank = -1;
                    var rankScore = -1;
                    var rankingYouHTML = "";
                    data.rankings.forEach((ranking) => {
                        const safeName = ranking.name.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        if(safeName==user.name){
                            isUser = true;
                            rank = ranking.rank;
                            rankScore = ranking.score.toLocaleString();
                            const badge = medals[ranking.rank - 1] || String(ranking.rank);
                            rankingYouHTML = `<div class="rankings-item${ranking.rank <= 3 ? ' is-top' : ''}">
                                <span class="rankings-rank">${badge}</span>
                                <span class="rankings-name">${safeName}</span>
                                <span class="rankings-score">${rankScore}</span>
                            </div>`;
                            rankingYou.innerHTML = rankingYouHTML;
                        }
                    });

                    if(isUser) {
                        rankingYou.addEventListener('click', (e) => {
                            var place = "0th";
                            switch (rank){
                                case 1:
                                    place = "1st";
                                    break;
                                case 2:
                                    place = "2nd";
                                    break;
                                case 3:
                                    place = "3rd";
                                    break;
                                default:
                                    place = `${rank}th`;
                            };
                            var cpyStr = `I am on Leuvenguessr's leaderboard at ${place} place with a score of ${rankScore}. Come join me at https://a25sewt408.studev.groept.be/public/`;
                            console.log(cpyStr);
                            navigator.clipboard.writeText(cpyStr).then(
                                () => {
                                    /* clipboard successfully set */
                                    rankingYou.innerHTML = `<div class="rankings-item${rank <= 3 ? ' is-top' : ''}">
                                        <span class="rankings-name">Copied to clipboard</span>
                                        <span class="rankings-score">${rankScore}</span>
                                    </div>`;
                                    setTimeout(() => {
                                        rankingYou.innerHTML = rankingYouHTML;
                                    }, 1000);
                                },
                                () => {
                                    /* clipboard write failed */
                                    rankingYou.innerHTML = `<div class="rankings-item${rank <= 3 ? ' is-top' : ''}">
                                        <span class="rankings-name">Error copying to clipboard</span>
                                        <span class="rankings-score">${rankScore}</span>
                                    </div>`;
                                    setTimeout(() => {
                                        rankingYou.innerHTML = rankingYouHTML;
                                    }, 1000);
                                },
                            );
                        });
                    }
                }
                catch{
                    rankingYou.innerHTML = '<div class="rankings-empty">No players yet — be the first!</div>';
                }

            }
        } catch {
            rankingsListEl.innerHTML = '<div class="rankings-empty">Failed to load rankings.</div>';
        }
    }

    function closeMobileMenu() {
        toggleHidden(mobileMenu, true);
    }

    function createMarkerButton(location) {
        const button = document.createElement("button");
        const label = document.createElement("span");

        button.type = "button";
        button.className = "map-pin";
        button.setAttribute("aria-label", location.name);

        label.className = "map-pin-label";
        label.textContent = location.name;
        button.appendChild(label);

        button.addEventListener("mouseenter", () => button.classList.add("is-hovered"));
        button.addEventListener("mouseleave", () => button.classList.remove("is-hovered"));
        button.addEventListener("focus", () => button.classList.add("is-hovered"));
        button.addEventListener("blur", () => button.classList.remove("is-hovered"));

        markerOverlay.appendChild(button);
        markerButtons.set(location.id, button);
        return button;
    }

    function queueMarkerRecompute() {
        if (recomputeQueued) {
            return;
        }

        recomputeQueued = true;
        window.requestAnimationFrame(() => {
            recomputeQueued = false;
            recomputeMarkers();
        });
    }

    function isFrontFacing(currentViewer, lat, lon) {
        const scene = currentViewer.scene;
        const position = window.Cesium.Cartesian3.fromDegrees(lon, lat, 120000);
        const screen = scene.cartesianToCanvasCoordinates(position);
        if (!screen) {
            return false;
        }

        const width = scene.canvas.clientWidth;
        const height = scene.canvas.clientHeight;
        if (screen.x < 0 || screen.x > width || screen.y < 0 || screen.y > height) {
            return false;
        }

        const ray = currentViewer.camera.getPickRay(screen);
        if (!ray) {
            return false;
        }

        const picked = scene.globe.pick(ray, scene);
        if (!picked) {
            return false;
        }

        return window.Cesium.Cartesian3.distance(picked, position) < 450000;
    }

    function recomputeMarkers() {
        if (!viewer || !markerOverlay || !window.Cesium) {
            return;
        }

        const visibleMarkers = [];

        playedLocations.forEach((location) => {
            const position = window.Cesium.Cartesian3.fromDegrees(location.lon, location.lat, 120000);
            const screen = viewer.scene.cartesianToCanvasCoordinates(position);

            if (!screen || !isFrontFacing(viewer, location.lat, location.lon)) {
                return;
            }

            visibleMarkers.push({
                location,
                x: screen.x,
                y: screen.y
            });
        });

        visibleMarkers.sort((left, right) => left.y - right.y);

        const occupiedPoints = [];
        const dedupeRadius = 26;

        visibleMarkers.forEach((marker) => {
            const button = markerButtons.get(marker.location.id) || createMarkerButton(marker.location);
            const isOverlapping = occupiedPoints.some((point) => {
                const dx = point.x - marker.x;
                const dy = point.y - marker.y;
                return Math.sqrt((dx * dx) + (dy * dy)) < dedupeRadius;
            });

            if (isOverlapping) {
                button.hidden = true;
                return;
            }

            occupiedPoints.push({ x: marker.x, y: marker.y });
            button.hidden = false;
            button.style.left = marker.x + "px";
            button.style.top = marker.y + "px";
        });

        markerButtons.forEach((button, id) => {
            const stillVisible = visibleMarkers.some((marker) => marker.location.id === id && !button.hidden);
            if (!stillVisible && !button.hidden) {
                button.hidden = true;
            }
        });
    }

    async function initCesium() {
        if (!globeContainer || !window.Cesium) {
            return;
        }

        if (!config.cesiumToken) {
            toggleHidden(tokenWarning, false);
            return;
        }

        try {
            window.Cesium.Ion.defaultAccessToken = config.cesiumToken;

            const terrain = await window.Cesium.createWorldTerrainAsync();
            viewer = new window.Cesium.Viewer(globeContainer, {
                terrainProvider: terrain,
                animation: false,
                timeline: false,
                geocoder: false,
                homeButton: false,
                sceneModePicker: false,
                navigationHelpButton: false,
                fullscreenButton: false,
                baseLayerPicker: false,
                infoBox: false,
                selectionIndicator: false,
                shouldAnimate: true,
                skyBox: undefined
            });

            viewer.scene.fxaa = true;
            viewer.scene.postProcessStages.fxaa.enabled = true;
            viewer.scene.backgroundColor = window.Cesium.Color.BLACK;
            viewer.cesiumWidget.creditContainer.style.display = "none";
            viewer.scene.canvas.style.touchAction = "none";
            viewer.scene.screenSpaceCameraController.enableCollisionDetection = false;
            viewer.scene.screenSpaceCameraController.enableRotate = true;
            viewer.scene.screenSpaceCameraController.enableZoom = true;
            viewer.scene.screenSpaceCameraController.enableTilt = true;
            viewer.scene.screenSpaceCameraController.enableLook = true;
            viewer.scene.screenSpaceCameraController.minimumZoomDistance = 9000000;
            viewer.scene.screenSpaceCameraController.maximumZoomDistance = 28000000;

            if (viewer.scene.skyAtmosphere) {
                viewer.scene.skyAtmosphere.show = false;
            }

            if (viewer.scene.globe) {
                viewer.scene.globe.enableLighting = false;
                viewer.scene.globe.showGroundAtmosphere = false;
                if ("dynamicAtmosphereLighting" in viewer.scene.globe) {
                    viewer.scene.globe.dynamicAtmosphereLighting = false;
                }
                if ("dynamicAtmosphereLightingFromSun" in viewer.scene.globe) {
                    viewer.scene.globe.dynamicAtmosphereLightingFromSun = false;
                }
            }

            viewer.camera.flyTo({
                destination: window.Cesium.Cartesian3.fromDegrees(20, 18, 20000000),
                duration: 0
            });

            playedLocations.forEach((location) => {
                viewer.entities.add({
                    id: "marker-" + location.id,
                    position: window.Cesium.Cartesian3.fromDegrees(location.lon, location.lat, 120000),
                    point: {
                        pixelSize: 8,
                        color: window.Cesium.Color.fromCssColorString("#ff5b6e"),
                        outlineColor: window.Cesium.Color.WHITE,
                        outlineWidth: 1,
                        scaleByDistance: new window.Cesium.NearFarScalar(3000000, 1.3, 25000000, 0.55),
                        translucencyByDistance: new window.Cesium.NearFarScalar(3000000, 1, 25000000, 0.65),
                        disableDepthTestDistance: Number.POSITIVE_INFINITY
                    }
                });
            });

            postRenderCallback = () => queueMarkerRecompute();
            viewer.scene.postRender.addEventListener(postRenderCallback);

            resizeCallback = () => queueMarkerRecompute();
            window.addEventListener("resize", resizeCallback);

            toggleHidden(tokenWarning, true);
            queueMarkerRecompute();
        } catch (error) {
            console.error("Failed to initialize Cesium", error);
            if (tokenWarning) {
                tokenWarning.textContent = "Cesium failed to initialize. Check the configured token or network access.";
                toggleHidden(tokenWarning, false);
            }
        }
    }

    function setPanelNav(tab) {
        panelNavButtons.forEach((btn) => {
            btn.classList.toggle("is-active", btn.dataset.panelNav === tab);
        });
        panelSections.forEach((section) => {
            section.classList.toggle("is-active", section.dataset.panelSection === tab);
        });
        if (tab === "regions") {
            loadPanelRegions();
        }
    }

    function showModeDetail(mode) {
        const playUrl = config.playUrl || "/";
        const hubUrl = config.challengeHubUrl || "/";
        const duelUrl = config.duelUrl || "/";
        const details = {
            leuven: {
                title: "Leuven Challenge",
                desc: "Guess locations around Leuven each day. Build your streak and climb the leaderboard!",
                action: `<a class="hero-button launch" href="${escapeHtml(playUrl)}">Play Now</a>`
            },
            world: {
                title: "World Challenge",
                desc: "Browse community-created challenge sets and guess locations around the world. Each set has its own leaderboard.",
                action: `<a class="hero-button launch" href="${escapeHtml(hubUrl)}">Browse Challenges</a>`
            },
            duel: {
                title: "Duel Challenge",
                desc: "Head-to-head live battles with fast rounds and direct score comparison.",
                action: `<a class="hero-button launch duel-menu" href="${escapeHtml(duelUrlProcess(duelUrl))}">Play Now</a>`
            }
        };
        const d = details[mode];
        if (!d) { return; }
        toggleHidden(modesList, true);
        toggleHidden(modeDetailEl, false);
        if (modeDetailBody) {
            modeDetailBody.innerHTML = `<div class="panel-detail-content"><h3>${d.title}</h3><p>${d.desc}</p>${d.action}</div>`;
        }
    }

    function showRegionDetail(region) {
        const hubUrl = config.challengeHubUrl || "/";
        toggleHidden(regionsList, true);
        toggleHidden(regionDetailEl, false);
        if (regionDetailBody) {
            regionDetailBody.innerHTML = `<div class="panel-detail-content"><h3>${escapeHtml(region.name)}</h3><p>Explore community challenges set in the ${escapeHtml(region.name)} area.</p><a class="hero-button launch" href="${escapeHtml(hubUrl)}?region=${encodeURIComponent(region.slug)}">Browse Challenges</a></div>`;
        }
    }

    let regionsLoaded = false;
    async function loadPanelRegions() {
        if (regionsLoaded || !regionsList) { return; }
        try {
            const url = (window.APP_API && window.APP_API.urls && window.APP_API.urls["/api/challenges/regions"])
                || apiUrl("/api/challenges/regions");
            const res = await fetch(url, { cache: "no-store" });
            const data = await res.json().catch(() => ({}));
            if (!data.success || !Array.isArray(data.regions) || data.regions.length === 0) {
                regionsList.innerHTML = '<div class="panel-loading">No regions found.</div>';
                return;
            }
            regionsLoaded = true;
            regionsList.innerHTML = data.regions.map((r) => `
                <button class="panel-entry-card is-available" type="button" data-region-slug="${escapeHtml(r.slug)}" data-region-name="${escapeHtml(r.name)}">
                    <div class="panel-entry-info"><h3>${escapeHtml(r.name)}</h3></div>
                    <span class="panel-entry-badge">Explore</span>
                </button>
            `).join("");
            regionsList.querySelectorAll("[data-region-slug]").forEach((btn) => {
                btn.addEventListener("click", () => {
                    showRegionDetail({ name: btn.dataset.regionName, slug: btn.dataset.regionSlug });
                });
            });
        } catch {
            regionsList.innerHTML = '<div class="panel-loading">Failed to load regions.</div>';
        }
    }

    function bindUi() {
        if (mobileMenuToggle && mobileMenu) {
            mobileMenuToggle.addEventListener("click", () => {
                toggleHidden(mobileMenu, !mobileMenu.hidden);
            });
        }

        openModeButtons.forEach((button) => button.addEventListener("click", openModal));
        closeModeButtons.forEach((button) => button.addEventListener("click", closeModal));

        if (modeModal) {
            modeModal.addEventListener("click", (event) => {
                if (event.target === modeModal) {
                    closeModal();
                }
            });
        }

        openRankingsButtons.forEach((button) => button.addEventListener("click", openRankingsModal));
        closeRankingsButtons.forEach((button) => button.addEventListener("click", closeRankingsModal));
        rankingsTabButtons.forEach((btn) => btn.addEventListener("click", () => setActiveRankingsTab(btn.dataset.rankingsTab)));

        if (rankingsModal) {
            rankingsModal.addEventListener("click", (event) => {
                if (event.target === rankingsModal) {
                    closeRankingsModal();
                }
            });
        }

        openAuthButtons.forEach((button) => button.addEventListener("click", () => openAuthModal(false)));
        closeAuthButtons.forEach((button) => button.addEventListener("click", closeAuthModal));

        if (authModal) {
            authModal.addEventListener("click", (event) => {
                if (event.target === authModal) {
                    closeAuthModal();
                }
            });
        }

        if (toSignupBtn) {
            toSignupBtn.addEventListener("click", () => showAuthView("signup"));
        }

        if (toLoginBtn) {
            toLoginBtn.addEventListener("click", () => showAuthView("login"));
        }

        if (loginForm) {
            loginForm.addEventListener("submit", submitLogin);
        }

        if (signupForm) {
            signupForm.addEventListener("submit", submitSignup);
        }

        if (logoutBtn) {
            logoutBtn.addEventListener("click", async () => {
                try {
                    await fetch(apiUrl("/api/auth/logout"), { method: "POST", cache: "no-store" });
                } catch {
                    // Local logout still clears the visible state.
                }
                clearStoredUser();
                applyUserState();
                disableIfLoggedOut();
            });
        }

        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                closeModal();
                closeRankingsModal();
                closePublishModal();
                closeAuthModal();
                closeMobileMenu();
            }
        });

        tabButtons.forEach((button) => {
            button.addEventListener("click", () => setActiveTab(button.dataset.tabTrigger));
        });

        panelNavButtons.forEach((btn) => {
            btn.addEventListener("click", () => setPanelNav(btn.dataset.panelNav));
        });

        document.querySelectorAll("[data-show-mode]").forEach((btn) => {
            btn.addEventListener("click", () => showModeDetail(btn.dataset.showMode));
        });

        document.querySelectorAll("[data-panel-back]").forEach((btn) => {
            btn.addEventListener("click", () => {
                const target = btn.dataset.panelBack;
                if (target === "modes") {
                    toggleHidden(modesList, false);
                    toggleHidden(modeDetailEl, true);
                } else if (target === "regions") {
                    toggleHidden(regionsList, false);
                    toggleHidden(regionDetailEl, true);
                }
            });
        });

        if (panelToggle && panel && panelReopen) {
            panelToggle.addEventListener("click", () => {
                toggleHidden(panel, true);
                toggleHidden(panelReopen, false);
            });

            panelReopen.addEventListener("click", () => {
                toggleHidden(panel, false);
                toggleHidden(panelReopen, true);
            });
        }

        openPublishButtons.forEach((button) => button.addEventListener("click", openPublishModal));
        closePublishButtons.forEach((button) => button.addEventListener("click", closePublishModal));

        if (publishModal) {
            publishModal.addEventListener("click", (event) => {
                if (event.target === publishModal) {
                    closePublishModal();
                }
            });
        }

        if (publishCountSelect) {
            const updateCount = () => setPublishSlotCount(Number(publishCountSelect.value));
            publishCountSelect.addEventListener("input", updateCount);
            publishCountSelect.addEventListener("change", updateCount);
        }

        if (publishFilePicker) {
            publishFilePicker.addEventListener("change", () => {
                const file = publishFilePicker.files && publishFilePicker.files[0] ? publishFilePicker.files[0] : null;
                if (!file || publishFilesBySlot.length === 0) {
                    return;
                }

                publishFilesBySlot[publishSelectedIndex] = file;
                publishPoints[publishSelectedIndex] = null;
                resolvePublishRegionSet();
                setPublishStatus("Click the map to set the answer point for this image.", false);
                selectPublishImage(publishSelectedIndex);
            });
        }

        if (publishLatInput && publishLngInput) {
            [publishLatInput, publishLngInput].forEach((input) => {
                input.addEventListener("change", () => {
                    const lat = Number(publishLatInput.value);
                    const lng = Number(publishLngInput.value);
                    if (Number.isFinite(lat) && Number.isFinite(lng)) {
                        setPublishPoint(lat, lng);
                    }
                });
            });
        }

        if (publishForm) {
            publishForm.addEventListener("submit", submitPublishForm);
        }
    }

    function togglePanelBack(){
        document.querySelectorAll("[data-panel-back]").forEach((btn) => {
            const target = btn.dataset.panelBack;
            if (target === "modes") {
                toggleHidden(modesList, false);
                toggleHidden(modeDetailEl, true);
            } else if (target === "regions") {
                toggleHidden(regionsList, false);
                toggleHidden(regionDetailEl, true);
            }
        });
    }

    function applyTopbarAuthButtons(isLoggedIn, isAdmin) {
        if (!isLoggedIn) {
            friendsNavButtons.forEach((btn) => { btn.hidden = true; });
            adminTopbarButtons.forEach((btn) => { btn.hidden = true; });
        } else if (isAdmin) {
            friendsNavButtons.forEach((btn) => { btn.hidden = true; });
            adminTopbarButtons.forEach((btn) => { btn.hidden = false; });
        } else {
            friendsNavButtons.forEach((btn) => { btn.hidden = false; });
            adminTopbarButtons.forEach((btn) => { btn.hidden = true; });
        }
    }

    function disableIfLoggedOut(){
        const user = getStoredUser();
        if(!user||!user.id){
            duelStatus.forEach((status) => {
                status.textContent = "Log in";
            });
            duelMenus.forEach((item) => {
               item.disabled = true;
               item.classList.add("disabled");
                if (item.hasAttribute("href")){
                    duelLink = item.getAttribute("href");
                    item.removeAttribute("href");
                }
            });
            applyTopbarAuthButtons(false, false);
        }
        togglePanelBack();
    }

    function enableIfLoggedIn(){
        const user = getStoredUser();
        if(user&&user.id){
            duelStatus.forEach((status) => {
                status.textContent = "Available";
            });
            duelMenus.forEach((item) => {
                item.disabled = false;
                item.classList.remove("disabled");
                if (item.nodeName == "A"&&duelLink!=null){
                    item.setAttribute("href",duelLink);
                }
            });
            applyTopbarAuthButtons(true, Boolean(user.isAdmin));
        }
        togglePanelBack();
    }

    function duelUrlProcess(id) {
        const base = config.duelUrl || "/play";
        return `${base}?mode=duel`;
    }

    function openPublishFromUrl() {
        const params = new URLSearchParams(window.location.search);
        if (params.get("publish") === "1" || window.location.hash === "#publish") {
            window.setTimeout(openPublishModal, 0);
        }
    }

    const taglines = [
        "Guess where. Beat the world.",
        "How well do you know the world?",
        "Every photo hides a location.",
        "Explore. Guess. Compete."
    ];
    const heroH1 = document.querySelector(".hero-copy h1");
    if (heroH1) {
        heroH1.textContent = taglines[Math.floor(Math.random() * taglines.length)];
    }

    bindUi();
    setPanelNav("modes");
    closeMobileMenu();
    closeModal();
    closeRankingsModal();
    closePublishModal();
    closeAuthModal();
    resetPublishWorkflow();
    applyUserState();
    initCesium();
    disableIfLoggedOut();
    enableIfLoggedIn();
    openPublishFromUrl();

    document.addEventListener("adminStateChange", (event) => {
        const user = getStoredUser();
        if (!user || !user.id) {
            return;
        }
        applyTopbarAuthButtons(true, Boolean(event.detail && event.detail.isAdmin));
    });

    window.addEventListener("pagehide", (event) => {
        if (event.persisted) {
            return;
        }

        if (viewer && !viewer.isDestroyed()) {
            if (postRenderCallback) {
                viewer.scene.postRender.removeEventListener(postRenderCallback);
            }
            if (resizeCallback) {
                window.removeEventListener("resize", resizeCallback);
            }
            viewer.destroy();
        }
    });
})();
