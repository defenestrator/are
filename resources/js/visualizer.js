import * as THREE from 'three';
import {GUI} from 'dat.gui';
import {EffectComposer} from 'three/examples/jsm/postprocessing/EffectComposer';
import {RenderPass} from 'three/examples/jsm/postprocessing/RenderPass';
import {UnrealBloomPass} from 'three/examples/jsm/postprocessing/UnrealBloomPass';
import {OutputPass} from 'three/examples/jsm/postprocessing/OutputPass';
import {LOG_PREFIX, averageFrequency, keepCapturing, openFile, readConfig, scaledLevel} from './visualizer-audio';
        
// On /overlay/visualizer, clear to transparent so OBS composites the sphere
// over the scene. The standalone /visualizer page keeps its black background.
const isOverlay = document.documentElement.dataset.overlay !== undefined;
const renderer = new THREE.WebGLRenderer({antialias: true, alpha: isOverlay});
if (isOverlay) {
    renderer.setClearColor(0x000000, 0);
}
const container = document.getElementById('visualizer-container');
if (container) {
    const containerRect = container.getBoundingClientRect();
    renderer.setSize(containerRect.width, containerRect.height || 256);
    container.appendChild(renderer.domElement);
} else {
    // Fallback to body if container not found
    renderer.setSize(window.innerWidth, window.innerHeight);
    document.body.appendChild(renderer.domElement);
}

const scene = new THREE.Scene();
const containerRect = container ? container.getBoundingClientRect() : { width: window.innerWidth, height: window.innerHeight };
const camera = new THREE.PerspectiveCamera(
    45,
    containerRect.width / containerRect.height,
    0.1,
    1000
);

const params = {
    red: 1.0,
    green: 1.0,
    blue: 1.0,
    threshold: 0.5,
    strength: 0.5,
    radius: 0.8
}

renderer.outputColorSpace = THREE.SRGBColorSpace;

const renderScene = new RenderPass(scene, camera);

const bloomPass = new UnrealBloomPass(new THREE.Vector2(containerRect.width, containerRect.height));
bloomPass.threshold = params.threshold;
bloomPass.strength = params.strength;
bloomPass.radius = params.radius;

const bloomComposer = new EffectComposer(renderer);
bloomComposer.addPass(renderScene);
bloomComposer.addPass(bloomPass);

const outputPass = new OutputPass();
bloomComposer.addPass(outputPass);

camera.position.set(0, -2, 14);
camera.lookAt(0, 0, 0);

const uniforms = {
    u_time: {type: 'f', value: 0.0},
    u_frequency: {type: 'f', value: 0.0},
    u_red: {type: 'f', value: 1.0},
    u_green: {type: 'f', value: 0.4},
    u_blue: {type: 'f', value: 1.0}
}

const mat = new THREE.ShaderMaterial({
    uniforms,
    vertexShader: document.getElementById('vertexshader').textContent,
    fragmentShader: document.getElementById('fragmentshader').textContent
});

const geo = new THREE.IcosahedronGeometry(4, 30 );
const mesh = new THREE.Mesh(geo, mat);
scene.add(mesh);
mesh.material.wireframe = true;

// What to listen to comes from #visualizer-config (see App\Support\VisualizerAudio).
const config = readConfig(document.getElementById('visualizer-config'));
const audioContext = new AudioContext();
let analyser = null;
let levels = new Uint8Array(0);

function useAnalyser(next) {
    analyser = next;
    levels = new Uint8Array(next ? next.frequencyBinCount : 0);
}

// Readable in OBS's log, which records browser-source console messages.
function reportAudio(status, detail) {
    document.documentElement.dataset.audio = status;
    (status === 'live' ? console.info : console.warn)(`${LOG_PREFIX} audio ${status}: ${detail}`);
}

// OBS's browser sources allow audio without a user gesture. Ordinary
// browsers may keep the context suspended until the first click or key.
audioContext.resume().catch(() => {});
['click', 'keydown'].forEach((type) => window.addEventListener(type, () => audioContext.resume(), {once: true}));

if (config.mode === 'device') {
    keepCapturing(audioContext, config.device, navigator.mediaDevices, {onAnalyser: useAnalyser, onStatus: reportAudio});
} else if (config.mode === 'file') {
    openFile(audioContext, '/A-Measure-of-My-Love-2025-10-02.mp3', config.audible, fetch).then(({analyser: fileAnalyser, source}) => {
        useAnalyser(fileAnalyser);

        if (config.audible) {
            // /visualizer: the old click-to-play demo, audible.
            window.addEventListener('click', () => audioContext.resume().then(() => source.start()), {once: true});
            reportAudio('waiting', 'Click to play the bundled track.');
        } else {
            // Overlay: analysed silently, so it never reaches the stream mix.
            source.start();
            reportAudio('live', 'Analysing the bundled track silently.');
        }
    }).catch((error) => reportAudio('error', `Could not load the bundled track: ${error}`));
} else {
    reportAudio('none', 'No audio source. Add ?audio=default or ?audio=<device label>.');
}

const gui = new GUI();

const colorsFolder = gui.addFolder('Colors');
colorsFolder.add(params, 'red', 0, 1).onChange(function(value) {
    uniforms.u_red.value = Number(value);
});
colorsFolder.add(params, 'green', 0, 1).onChange(function(value) {
    uniforms.u_green.value = Number(value);
});
colorsFolder.add(params, 'blue', 0, 1).onChange(function(value) {
    uniforms.u_blue.value = Number(value);
});

const bloomFolder = gui.addFolder('Bloom');
bloomFolder.add(params, 'threshold', 0, 1).onChange(function(value) {
    bloomPass.threshold = Number(value);
});
bloomFolder.add(params, 'strength', 0, 3).onChange(function(value) {
    bloomPass.strength = Number(value);
});
bloomFolder.add(params, 'radius', 0, 1).onChange(function(value) {
    bloomPass.radius = Number(value);
});

let mouseX = 0;
let mouseY = 0;
document.addEventListener('mousemove', function(e) {
    const currentContainer = container || document.body;
    const rect = currentContainer.getBoundingClientRect();
    let windowHalfX = rect.width / 2;
    let windowHalfY = rect.height / 2;
    mouseX = ((e.clientX - rect.left) - windowHalfX) / 100;
    mouseY = ((e.clientY - rect.top) - windowHalfY) / 100;
});
const style = document.createElement('style');
style.innerHTML = `
.dg .close-button, .dg .open-button {
    display: none !important;
}
`;
document.head.appendChild(style);

// Also hide the Color and Bloom folders if needed
colorsFolder.domElement.style.display = 'none';
bloomFolder.domElement.style.display = 'none';
const clock = new THREE.Clock();
function animate() {
    const elapsed = clock.getElapsedTime() * 0.3; // Slo
    if (config.motion === 'orbit') {
        // Nobody moves a mouse over an OBS source: drift slowly instead.
        const t = clock.getElapsedTime();
        camera.position.x = Math.sin(t * 0.12) * 3;
        camera.position.y = -2 + Math.sin(t * 0.08) * 1.5;
    } else {
        camera.position.x += (mouseX - camera.position.x) * .05;
        camera.position.y += (-mouseY - camera.position.y) * 0.5;
    }
    camera.lookAt(scene.position);

    // Smooth color animations: values between 0.2 and 1.0 over time
    uniforms.u_red.value   = 0.2 + 0.8 * (0.5 + 0.5 * Math.sin(elapsed));
    uniforms.u_green.value = 0.2 + 0.8 * (0.5 + 0.5 * Math.sin(elapsed + 2.0));
    uniforms.u_blue.value  = 0.2 + 0.8 * (0.5 + 0.5 * Math.sin(elapsed + 4.0));

    // Smooth bloom animations: values between 0.0 and 0.5 over time
    bloomPass.threshold = 0.5 * (0.1 + 0.5 * Math.sin(elapsed));
    bloomPass.strength  = 0.5 * (0.1 + 0.5 * Math.sin(elapsed + 3.0));
    bloomPass.radius    = 0.5 * (0.1 + 0.5 * Math.sin(elapsed + 6.0));

    uniforms.u_time.value = clock.getElapsedTime();
    uniforms.u_frequency.value = scaledLevel(averageFrequency(analyser, levels), config.gain);


    // UnrealBloomPass spreads the sphere's alpha across the whole frame, which
    // would lay a dark wash over the stream. Overlays render the scene directly
    // and get their glow from a CSS drop-shadow instead (see app.css).
    if (isOverlay) {
        renderer.render(scene, camera);
    } else {
        bloomComposer.render();
    }
    requestAnimationFrame(animate);
}
animate();

window.addEventListener('resize', function() {
    const newContainerRect = container ? container.getBoundingClientRect() : { width: window.innerWidth, height: window.innerHeight };
    camera.aspect = newContainerRect.width / newContainerRect.height;
    camera.updateProjectionMatrix();
    renderer.setSize(newContainerRect.width, newContainerRect.height);
    bloomComposer.setSize(newContainerRect.width, newContainerRect.height);
});
    