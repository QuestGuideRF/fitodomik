(function () {
    'use strict';
    var scenes = document.querySelectorAll('[data-components-scroll]');
    if (!scenes.length) return;
    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }
    function preloadFrame(src, loaded) {
        if (!src || loaded[src]) return;
        var preloadImage = new Image();
        loaded[src] = preloadImage;
        preloadImage.decoding = 'async';
        preloadImage.onload = function () {
            loaded[src] = true;
        };
        preloadImage.onerror = function () {
            delete loaded[src];
        };
        preloadImage.src = src;
    }
    scenes.forEach(function (scene) {
        var frames = [];
        try {
            frames = JSON.parse(scene.dataset.frames || '[]');
        } catch (error) {
            frames = [];
        }
        if (!frames.length) return;
        var image = scene.querySelector('[data-frame-image]');
        var progress = scene.querySelector('[data-frame-progress]');
        var counter = scene.querySelector('[data-frame-counter]');
        var finalHint = scene.querySelector('[data-final-hint]');
        var loaded = {};
        var currentFrame = -1;
        var ticking = false;
        preloadFrame(frames[0], loaded);
        preloadFrame(frames[1], loaded);
        preloadFrame(frames[frames.length - 1], loaded);
        function setFrame(index) {
            index = clamp(index, 0, frames.length - 1);
            if (index === currentFrame) return;
            currentFrame = index;
            if (image) {
                image.src = frames[index];
            }
            if (counter) {
                counter.textContent = 'Кадр ' + (index + 1) + ' / ' + frames.length;
            }
            if (progress) {
                progress.style.transform = 'scaleX(' + ((index + 1) / frames.length) + ')';
            }
            var isFinal = index === frames.length - 1;
            scene.classList.toggle('is-final', isFinal);
            if (finalHint) {
                finalHint.hidden = !isFinal;
            }
            preloadFrame(frames[index - 1], loaded);
            preloadFrame(frames[index + 1], loaded);
            preloadFrame(frames[index + 2], loaded);
        }
        function update() {
            ticking = false;
            var rect = scene.getBoundingClientRect();
            var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
            var scrollDistance = Math.max(1, scene.offsetHeight - viewportHeight);
            var sceneProgress = clamp(-rect.top / scrollDistance, 0, 1);
            var frameIndex = Math.round(sceneProgress * (frames.length - 1));
            setFrame(frameIndex);
        }
        function requestUpdate() {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(update);
        }
        window.addEventListener('scroll', requestUpdate, { passive: true });
        window.addEventListener('resize', requestUpdate);
        window.addEventListener('orientationchange', requestUpdate);
        requestUpdate();
    });
})();
