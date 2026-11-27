(function () {
    const listEl = document.querySelector("[data-creator-list]");
    const refreshMineButton = document.querySelector("[data-refresh-mine]");
    const canvas = document.querySelector("[data-stats-canvas]");
    const selectedTitleEl = document.querySelector("[data-selected-title]");

    const USER_KEY = "lg_user";
    const CACHE_KEY = "lg_creator_sets_cache_v3";
    const CACHE_TTL_MS = 5 * 60 * 1000;
    const MAX_CHALLENGE_SET_DIAGONAL_METERS = 30000;
    const SPREAD_ERROR = "Images are too spread out. Split this into multiple challenge sets.";
    const detailCache = new Map();
    const api = (path) => window.apiUrl ? window.apiUrl(path) : `${window.Leuvenguesser?.basePath || ""}${path}`;

    let activeModal = null;

    function escapeHtml(value) {
        return String(value || "")
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

    async function resolveRegionName(regionPayload) {
        const lat = Number(regionPayload.centerLat);
        const lng = Number(regionPayload.centerLng);
        let name = regionPayload.name || `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
        const bounds = {
            swLat: Number(regionPayload.swLat),
            swLng: Number(regionPayload.swLng),
            neLat: Number(regionPayload.neLat),
            neLng: Number(regionPayload.neLng)
        };

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
        } catch {
            // Keep the local fallback name.
        }

        try {
            const res = await fetch(api("/api/challenges/regions/resolve"), {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ lat, lng, name, bounds }),
                cache: "no-store"
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.success && data.region && data.region.name) {
                return data.region.name;
            }
        } catch {
            // The save endpoint can still create the region from the submitted bounds.
        }

        return name;
    }

    function getStoredUser() {
        try {
            const raw = localStorage.getItem(USER_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    function currentIdentity() {
        const user = getStoredUser();
        return {
            id: user && user.id ? Number(user.id) : null,
            name: user && user.name ? String(user.name) : ""
        };
    }

    function identityKey() {
        const user = currentIdentity();
        return `${user.id || 0}:${user.name}`;
    }

    function ownerParams() {
        const user = currentIdentity();
        const params = new URLSearchParams();
        if (user.id) {
            params.set("userId", String(user.id));
        }
        if (user.name) {
            params.set("userName", user.name);
        }
        return params;
    }

    function ownerPayload() {
        const user = currentIdentity();
        return {
            userId: user.id,
            userName: user.name,
            authorName: user.name || "Local Creator"
        };
    }

    function withOwnerQuery(url) {
        const params = ownerParams();
        const query = params.toString();
        return query ? `${url}?${query}` : url;
    }

    function readCachedMine() {
        try {
            const cached = JSON.parse(localStorage.getItem(CACHE_KEY) || "null");
            if (!cached || cached.identity !== identityKey() || Date.now() - cached.savedAt > CACHE_TTL_MS) {
                return null;
            }
            return Array.isArray(cached.challenges) ? cached.challenges : null;
        } catch {
            return null;
        }
    }

    function writeCachedMine(challenges) {
        localStorage.setItem(CACHE_KEY, JSON.stringify({
            identity: identityKey(),
            savedAt: Date.now(),
            challenges
        }));
    }

    function invalidateCreatorCache() {
        localStorage.removeItem(CACHE_KEY);
        detailCache.clear();
    }

    async function loadMine(force) {
        if (!listEl) {
            return;
        }

        if (!force) {
            const cached = readCachedMine();
            if (cached) {
                renderMine(cached);
                return;
            }
        }

        listEl.innerHTML = '<div class="empty-state">Loading your challenge sets...</div>';
        const res = await fetch(withOwnerQuery(api("/api/challenges/mine")), { cache: "no-store" });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            listEl.innerHTML = '<div class="empty-state">Failed to load your challenge sets.</div>';
            return;
        }

        const challenges = Array.isArray(data.challenges) ? data.challenges : [];
        writeCachedMine(challenges);
        renderMine(challenges);
    }

    function renderMine(challenges) {
        if (!listEl) {
            return;
        }

        if (challenges.length === 0) {
            listEl.innerHTML = '<div class="empty-state">No published sets for this login yet.</div>';
            drawStats([]);
            return;
        }

        listEl.innerHTML = challenges.map((challenge) => {
            return `<article class="creator-card">
                <div class="challenge-meta">
                    <span class="metric-pill">${escapeHtml(challenge.region_name)}</span>
                    <span class="metric-pill">${Number(challenge.round_count || 0)} images</span>
                </div>
                <h3>${escapeHtml(challenge.title)}</h3>
                <p>${escapeHtml(challenge.description || "No description.")}</p>
                <div class="metric-row">
                    <span class="metric-pill">${Number(challenge.play_count || 0)} plays</span>
                    <span class="metric-pill">${Number(challenge.like_count || 0)} likes</span>
                    <span class="metric-pill">${Number(challenge.comment_count || 0)} comments</span>
                </div>
                <div class="card-actions">
                    <button class="secondary-action" type="button" data-view-id="${challenge.id}">Details</button>
                    <button class="secondary-action" type="button" data-stats-id="${challenge.id}" data-title="${escapeHtml(challenge.title)}">Stats</button>
                    <button class="secondary-action" type="button" data-edit-id="${challenge.id}">Edit</button>
                    <button class="secondary-action danger-action" type="button" data-delete-id="${challenge.id}" data-title="${escapeHtml(challenge.title)}">Delete</button>
                </div>
            </article>`;
        }).join("");

        bindMineActions();
        loadStats(challenges[0].id, challenges[0].title);
    }

    function bindMineActions() {
        listEl.querySelectorAll("[data-view-id]").forEach((button) => {
            button.addEventListener("click", () => openDetailModal(Number(button.dataset.viewId)));
        });
        listEl.querySelectorAll("[data-stats-id]").forEach((button) => {
            button.addEventListener("click", () => loadStats(button.dataset.statsId, button.dataset.title));
        });
        listEl.querySelectorAll("[data-edit-id]").forEach((button) => {
            button.addEventListener("click", () => openEditModal(Number(button.dataset.editId), false));
        });
        listEl.querySelectorAll("[data-delete-id]").forEach((button) => {
            button.addEventListener("click", () => openDeleteModal(Number(button.dataset.deleteId), button.dataset.title || "this set"));
        });
    }

    async function getChallenge(id, force) {
        if (!force && detailCache.has(id)) {
            return detailCache.get(id);
        }
        const res = await fetch(api(`/api/challenges/${id}`), { cache: "no-store" });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success || !data.challenge) {
            throw new Error(data.error || "Unable to load challenge set.");
        }
        detailCache.set(id, data.challenge);
        return data.challenge;
    }

    function openModal(title, body, afterOpen) {
        closeModal();
        const modal = document.createElement("div");
        modal.className = "modal-backdrop";
        modal.innerHTML = `<section class="challenge-modal" role="dialog" aria-modal="true">
            <div class="modal-head">
                <h2>${escapeHtml(title)}</h2>
                <button class="icon-button" type="button" data-modal-close aria-label="Close">x</button>
            </div>
            <div class="modal-body">${body}</div>
        </section>`;
        document.body.appendChild(modal);
        activeModal = modal;
        modal.addEventListener("click", (event) => {
            if (event.target === modal || event.target.closest("[data-modal-close]")) {
                closeModal();
            }
        });
        if (afterOpen) {
            afterOpen(modal);
        }
    }

    function closeModal() {
        if (activeModal) {
            activeModal.remove();
            activeModal = null;
        }
    }

    async function openDetailModal(id) {
        try {
            const challenge = await getChallenge(id, false);
            const comments = (challenge.comments || []).map((comment) => {
                return `<div class="comment-row">
                    <strong>${escapeHtml(comment.author_name || "Player")}</strong>
                    <span>${escapeHtml(comment.body)}</span>
                </div>`;
            }).join("") || '<div class="empty-state">No comments yet.</div>';
            const imageRows = (challenge.locations || []).map((location, index) => {
                return `<div class="image-manage-row">
                    <img src="${api(`/api/game/locations/${location.id}/photo`)}" alt="" onerror="this.style.display='none'">
                    <div>
                        <strong>Image ${index + 1}</strong>
                        <span>${Number(location.lat).toFixed(5)}, ${Number(location.lng).toFixed(5)}</span>
                    </div>
                </div>`;
            }).join("") || '<div class="empty-state">No images in this set.</div>';
            const user = currentIdentity();
            const authorName = user.name || "Player";

            openModal(challenge.title, `<div class="modal-stack">
                <p class="modal-description">${escapeHtml(challenge.description || "No description.")}</p>
                <div class="metric-row">
                    <span class="metric-pill">${escapeHtml(challenge.region_name)}</span>
                    <span class="metric-pill">${Number((challenge.locations || []).length)} images</span>
                    <span class="metric-pill">${Number(challenge.play_count || 0)} plays</span>
                    <span class="metric-pill">${Number(challenge.like_count || 0)} likes</span>
                </div>
                <h3>Images and Answer Coordinates</h3>
                <div class="image-manage-list">${imageRows}</div>
                <h3>Comments</h3>
                <div class="comment-list">${comments}</div>
                <form class="modal-form" data-comment-form>
                    <p class="modal-description">Posting as ${escapeHtml(authorName)}</p>
                    <textarea name="body" maxlength="800" placeholder="Add a comment" required></textarea>
                    <button class="primary-action" type="submit">Post Comment</button>
                    <span data-modal-status></span>
                </form>
            </div>`, (modal) => {
                modal.querySelector("[data-comment-form]").addEventListener("submit", async (event) => {
                    event.preventDefault();
                    const form = event.currentTarget;
                    const body = form.elements.body.value.trim();
                    if (!body) {
                        return;
                    }
                    const res = await fetch(api(`/api/challenges/${id}/comments`), {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ authorName, body }),
                        cache: "no-store"
                    });
                    if (res.ok) {
                        invalidateCreatorCache();
                        await getChallenge(id, true);
                        openDetailModal(id);
                    } else {
                        modal.querySelector("[data-modal-status]").textContent = "Comment failed.";
                    }
                });
            });
        } catch (error) {
            openModal("Details", `<div class="empty-state">${escapeHtml(error.message)}</div>`);
        }
    }

    async function openDeleteModal(id, title) {
        openModal("Delete Challenge Set", `<div class="modal-stack">
            <p>Delete <strong>${escapeHtml(title)}</strong>? This removes the set and its images.</p>
            <div class="modal-actions">
                <button class="secondary-action" type="button" data-modal-close>Cancel</button>
                <button class="primary-action danger-action" type="button" data-confirm-delete>Delete</button>
            </div>
            <span data-modal-status></span>
        </div>`, (modal) => {
            modal.querySelector("[data-confirm-delete]").addEventListener("click", async () => {
                const res = await fetch(withOwnerQuery(api(`/api/challenges/${id}/delete`)), { method: "POST", cache: "no-store" });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.success) {
                    modal.querySelector("[data-modal-status]").textContent = data.error || "Delete failed.";
                    return;
                }
                invalidateCreatorCache();
                closeModal();
                loadMine(true);
            });
        });
    }

    async function openEditModal(id, force) {
        try {
            const challenge = await getChallenge(id, force);
            const locationRows = renderLocationRows(challenge);
            openModal("Edit Challenge Set", `<form class="modal-form edit-modal-form" data-edit-form>
                <input name="title" type="text" maxlength="120" value="${escapeHtml(challenge.title)}" required>
                <textarea name="description" maxlength="1200" placeholder="Description">${escapeHtml(challenge.description || "")}</textarea>
                <input name="regionName" type="text" maxlength="90" value="${escapeHtml(challenge.region_name || "")}" placeholder="Region will be generated from all answer points" readonly required>
                <div class="metric-row">
                    <span class="metric-pill">${Number((challenge.locations || []).length)} images</span>
                    <span class="metric-pill">${Number(challenge.play_count || 0)} plays</span>
                    <span class="metric-pill">${Number(challenge.like_count || 0)} likes</span>
                </div>
                <h3>Images</h3>
                <div class="image-manage-list">${locationRows}</div>
                <h3>Add Images</h3>
                <input name="images[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-edit-images>
                <div class="creator-map modal-map" data-edit-map></div>
                <div class="creator-rounds" data-edit-rounds>
                    <div class="empty-state">Select images, then choose one answer point per image.</div>
                </div>
                <div class="modal-actions">
                    <span data-modal-status></span>
                    <button class="secondary-action" type="button" data-modal-close>Cancel</button>
                    <button class="primary-action" type="submit">Save Changes</button>
                </div>
            </form>`, (modal) => initEditModal(modal, challenge));
        } catch (error) {
            openModal("Edit Challenge Set", `<div class="empty-state">${escapeHtml(error.message)}</div>`);
        }
    }

    function renderLocationRows(challenge) {
        const locations = challenge.locations || [];
        if (locations.length === 0) {
            return '<div class="empty-state">No images in this set.</div>';
        }

        const canDelete = locations.length > 1;

        return locations.map((location, index) => {
            return `<div class="image-manage-row">
                <img src="${api(`/api/game/locations/${location.id}/photo`)}" alt="" onerror="this.style.display='none'">
                <div>
                    <strong>Image ${index + 1}</strong>
                    <span>${Number(location.lat).toFixed(5)}, ${Number(location.lng).toFixed(5)}</span>
                </div>
                <button class="secondary-action danger-action" type="button" data-delete-location="${location.id}" ${canDelete ? "" : "disabled"}>${canDelete ? "Delete" : "Required"}</button>
            </div>`;
        }).join("");
    }

    function initEditModal(modal, challenge) {
        const form = modal.querySelector("[data-edit-form]");
        const imagesInput = modal.querySelector("[data-edit-images]");
        const roundsEl = modal.querySelector("[data-edit-rounds]");
        const mapEl = modal.querySelector("[data-edit-map]");
        const statusEl = modal.querySelector("[data-modal-status]");

        let map = null;
        let marker = null;
        let activeIndex = 0;
        let points = [];
        let regionResolving = false;

        function files() {
            return imagesInput && imagesInput.files ? Array.from(imagesInput.files) : [];
        }

        function setStatus(message, isError) {
            statusEl.textContent = message;
            statusEl.style.color = isError ? "#ffb7c2" : "#b7ffd6";
        }

        function updateMarker() {
            const point = points[activeIndex];
            if (!map || !point) {
                if (marker && map) {
                    map.removeLayer(marker);
                    marker = null;
                }
                return;
            }
            const latLng = window.L.latLng(point.lat, point.lng);
            if (marker) {
                marker.setLatLng(latLng);
            } else {
                marker = window.L.marker(latLng).addTo(map);
            }
        }

        function renderRounds() {
            const selectedFiles = files();
            if (selectedFiles.length === 0) {
                roundsEl.innerHTML = '<div class="empty-state">Select images, then choose one answer point per image.</div>';
                return;
            }
            roundsEl.innerHTML = selectedFiles.map((file, index) => {
                const point = points[index];
                return `<div class="round-row${index === activeIndex ? " is-active" : ""}">
                    <button type="button" data-round-index="${index}">${index + 1}. ${escapeHtml(file.name)}</button>
                    <input value="${point ? point.lat.toFixed(6) : ""}" placeholder="Latitude" readonly>
                    <input value="${point ? point.lng.toFixed(6) : ""}" placeholder="Longitude" readonly>
                </div>`;
            }).join("");
            roundsEl.querySelectorAll("[data-round-index]").forEach((button) => {
                button.addEventListener("click", () => {
                    activeIndex = Number(button.dataset.roundIndex);
                    updateMarker();
                    renderRounds();
                });
            });
        }

        function allRegionPoints() {
            const existingPoints = (challenge.locations || []).map((location) => ({
                lat: Number(location.lat),
                lng: Number(location.lng)
            })).filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng));

            return existingPoints.concat(points.filter(Boolean));
        }

        async function resolveEditRegion() {
            if (!form.elements.regionName) {
                return;
            }

            const pointsForRegion = allRegionPoints();
            regionResolving = true;
            form.elements.regionName.value = "Resolving region...";

            try {
                assertSpreadAllowed(pointsForRegion);
                const regionPayload = buildRegionPayload(pointsForRegion, form.elements.title.value);
                if (!regionPayload) {
                    form.elements.regionName.value = "";
                    return;
                }
                form.elements.regionName.value = await resolveRegionName(regionPayload);
            } catch (error) {
                form.elements.regionName.value = "";
                setStatus(error.message || SPREAD_ERROR, true);
            } finally {
                regionResolving = false;
            }
        }

        function initMap() {
            if (!window.L || !mapEl) {
                return;
            }
            map = window.L.map(mapEl, {
                minZoom: 2,
                maxZoom: 19,
                maxBounds: window.L.latLngBounds([-85, -180], [85, 180]),
                maxBoundsViscosity: 1.0
            }).setView([Number(challenge.center_lat || 20), Number(challenge.center_lng || 0)], 4);

            window.L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                maxZoom: 19,
                attribution: "&copy; OpenStreetMap contributors"
            }).addTo(map);

            map.on("click", (event) => {
                points[activeIndex] = { lat: event.latlng.lat, lng: event.latlng.lng };
                updateMarker();
                renderRounds();
                resolveEditRegion();
            });
            setTimeout(() => map.invalidateSize(), 80);
        }

        imagesInput.addEventListener("change", () => {
            points = files().map(() => null);
            activeIndex = 0;
            updateMarker();
            renderRounds();
            resolveEditRegion();
        });

        modal.querySelectorAll("[data-delete-location]").forEach((button) => {
            button.addEventListener("click", async () => {
                if (button.disabled) {
                    return;
                }
                const res = await fetch(withOwnerQuery(api(`/api/challenges/${challenge.id}/locations/${button.dataset.deleteLocation}`)), {
                    method: "POST",
                    cache: "no-store"
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.success) {
                    setStatus(data.error || "Image delete failed.", true);
                    return;
                }
                invalidateCreatorCache();
                await loadMine(true);
                openEditModal(Number(challenge.id), true);
            });
        });

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const selectedFiles = files();
            if (selectedFiles.some((_, index) => !points[index])) {
                setStatus("Each new image needs an answer point.", true);
                return;
            }

            if (regionResolving) {
                setStatus("Wait for the region name to finish resolving.", true);
                return;
            }

            const pointsForRegion = allRegionPoints();
            let regionPayload = null;
            try {
                assertSpreadAllowed(pointsForRegion);
                regionPayload = buildRegionPayload(pointsForRegion, form.elements.title.value);
            } catch (error) {
                setStatus(error.message || SPREAD_ERROR, true);
                return;
            }
            if (!regionPayload) {
                setStatus("Choose answer points so the region can be generated.", true);
                return;
            }

            const regionName = form.elements.regionName.value.trim() || await resolveRegionName(regionPayload);
            form.elements.regionName.value = regionName;

            setStatus("Saving...", false);
            const payload = {
                title: form.elements.title.value,
                description: form.elements.description.value,
                regionId: "",
                regionName,
                regionLat: regionPayload.centerLat,
                regionLng: regionPayload.centerLng,
                regionSwLat: regionPayload.swLat,
                regionSwLng: regionPayload.swLng,
                regionNeLat: regionPayload.neLat,
                regionNeLng: regionPayload.neLng,
                ...ownerPayload()
            };
            const metaRes = await fetch(api(`/api/challenges/${challenge.id}`), {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
                cache: "no-store"
            });
            const metaData = await metaRes.json().catch(() => ({}));
            if (!metaRes.ok || !metaData.success) {
                setStatus(metaData.error || "Save failed.", true);
                return;
            }

            if (selectedFiles.length > 0) {
                const formData = new FormData();
                const compressedFiles = await compressFilesForUpload(selectedFiles, setStatus);
                compressedFiles.forEach((file) => formData.append("images[]", file));
                points.forEach((point) => {
                    formData.append("lats[]", point.lat);
                    formData.append("lngs[]", point.lng);
                });
                const owner = ownerPayload();
                if (owner.userId) {
                    formData.append("userId", owner.userId);
                }
                if (owner.userName) {
                    formData.append("userName", owner.userName);
                }
                formData.append("regionName", regionName);
                const imageRes = await fetch(api(`/api/challenges/${challenge.id}/locations`), {
                    method: "POST",
                    body: formData,
                    cache: "no-store"
                });
                const imageData = await imageRes.json().catch(() => ({}));
                if (!imageRes.ok || !imageData.success) {
                    setStatus(imageData.error || "Image upload failed.", true);
                    return;
                }
            }

            invalidateCreatorCache();
            setStatus("Saved.", false);
            await loadMine(true);
            openEditModal(Number(challenge.id), true);
        });

        initMap();
        renderRounds();
        resolveEditRegion();
    }

    async function loadStats(id, title) {
        if (selectedTitleEl) {
            selectedTitleEl.textContent = title || "";
        }
        const res = await fetch(api(`/api/challenges/${id}/stats`), { cache: "no-store" });
        const data = await res.json().catch(() => ({}));
        drawStats(res.ok && data.success ? data.hourly : []);
    }

    function drawStats(rows) {
        if (!canvas) {
            return;
        }

        const ctx = canvas.getContext("2d");
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.strokeStyle = "rgba(255,255,255,0.18)";
        ctx.fillStyle = "rgba(255,255,255,0.68)";
        ctx.font = "13px Arial";
        ctx.strokeRect(42, 18, canvas.width - 62, canvas.height - 54);

        const values = rows.map((row) => Number(row.plays || 0));
        const max = Math.max(1, ...values);
        if (rows.length === 0) {
            ctx.fillText("No plays in the last 7 days.", 58, 136);
            return;
        }

        ctx.beginPath();
        rows.forEach((row, index) => {
            const x = 42 + ((canvas.width - 62) * index / Math.max(1, rows.length - 1));
            const y = 18 + (canvas.height - 54) - ((canvas.height - 54) * Number(row.plays || 0) / max);
            if (index === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
        });
        ctx.strokeStyle = "#5fd4ff";
        ctx.lineWidth = 3;
        ctx.stroke();

        ctx.fillStyle = "#9ce95e";
        rows.forEach((row, index) => {
            const x = 42 + ((canvas.width - 62) * index / Math.max(1, rows.length - 1));
            const y = 18 + (canvas.height - 54) - ((canvas.height - 54) * Number(row.plays || 0) / max);
            ctx.beginPath();
            ctx.arc(x, y, 4, 0, Math.PI * 2);
            ctx.fill();
        });
    }

    async function compressFilesForUpload(selectedFiles, setStatus) {
        const limit = 1.8 * 1024 * 1024;
        const compressed = [];
        for (const file of selectedFiles) {
            if (file.size <= limit) {
                compressed.push(file);
                continue;
            }
            setStatus(`Compressing ${file.name}...`, false);
            compressed.push(await compressImage(file, limit));
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
                canvas.getContext("2d").drawImage(image, 0, 0, canvas.width, canvas.height);

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

    if (refreshMineButton) {
        refreshMineButton.addEventListener("click", () => loadMine(true));
    }

    loadMine(false);
})();
