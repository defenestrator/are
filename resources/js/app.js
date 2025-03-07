import './bootstrap';

// import Alpine from 'alpinejs';
// import * as THREE from 'three';
// import {GUI} from 'dat.gui';
// import {EffectComposer} from 'three/examples/jsm/postprocessing/EffectComposer';
// import {RenderPass} from 'three/examples/jsm/postprocessing/RenderPass';
// import {UnrealBloomPass} from 'three/examples/jsm/postprocessing/UnrealBloomPass';
// import {OutputPass} from 'three/examples/jsm/postprocessing/OutputPass';

// const renderer = new THREE.WebGLRenderer({antialias: true});
// renderer.setSize(window.innerWidth, window.innerHeight);
// document.body.appendChild(renderer.domElement);

// const scene = new THREE.Scene();
// const camera = new THREE.PerspectiveCamera(
// 	45,
// 	window.innerWidth / window.innerHeight,
// 	0.1,
// 	1000
// );

// const params = {
// 	red: 1.0,
// 	green: 1.0,
// 	blue: 1.0,
// 	threshold: 0.5,
// 	strength: 0.5,
// 	radius: 0.8
// }

// renderer.outputColorSpace = THREE.SRGBColorSpace;

// const renderScene = new RenderPass(scene, camera);

// const bloomPass = new UnrealBloomPass(new THREE.Vector2(window.innerWidth, window.innerHeight));
// bloomPass.threshold = params.threshold;
// bloomPass.strength = params.strength;
// bloomPass.radius = params.radius;

// const bloomComposer = new EffectComposer(renderer);
// bloomComposer.addPass(renderScene);
// bloomComposer.addPass(bloomPass);

// const outputPass = new OutputPass();
// bloomComposer.addPass(outputPass);

// camera.position.set(0, -2, 14);
// camera.lookAt(0, 0, 0);

// const uniforms = {
// 	u_time: {type: 'f', value: 0.0},
// 	u_frequency: {type: 'f', value: 0.0},
// 	u_red: {type: 'f', value: 1.0},
// 	u_green: {type: 'f', value: 0.4},
// 	u_blue: {type: 'f', value: 1.0}
// }

// const mat = new THREE.ShaderMaterial({
// 	uniforms,
// 	vertexShader: document.getElementById('vertexshader').textContent,
// 	fragmentShader: document.getElementById('fragmentshader').textContent
// });

// const geo = new THREE.IcosahedronGeometry(4, 30 );
// const mesh = new THREE.Mesh(geo, mat);
// scene.add(mesh);
// mesh.material.wireframe = true;

// const listener = new THREE.AudioListener();
// camera.add(listener);

// const sound = new THREE.Audio(listener);
// const audioLoader = new THREE.AudioLoader();
// audioLoader.load('/Intro.mp3', function(buffer) {
// 	sound.setBuffer(buffer);
// 	sound.loop = true;
// 	sound.setVolume(1);	
// 	window.addEventListener('click', function() {
// 		sound.play();
		
// 	});
// });

// const analyser = new THREE.AudioAnalyser(sound, 32);

// const gui = new GUI();

// const colorsFolder = gui.addFolder('Colors');
// colorsFolder.add(params, 'red', 0, 1).onChange(function(value) {
// 	uniforms.u_red.value = Number(value);
// });
// colorsFolder.add(params, 'green', 0, 1).onChange(function(value) {
// 	uniforms.u_green.value = Number(value);
// });
// colorsFolder.add(params, 'blue', 0, 1).onChange(function(value) {
// 	uniforms.u_blue.value = Number(value);
// });

// const bloomFolder = gui.addFolder('Bloom');
// bloomFolder.add(params, 'threshold', 0, 1).onChange(function(value) {
// 	bloomPass.threshold = Number(value);
// });
// bloomFolder.add(params, 'strength', 0, 3).onChange(function(value) {
// 	bloomPass.strength = Number(value);
// });
// bloomFolder.add(params, 'radius', 0, 1).onChange(function(value) {
// 	bloomPass.radius = Number(value);
// });

// let mouseX = 0;
// let mouseY = 0;
// document.addEventListener('mousemove', function(e) {
// 	let windowHalfX = window.innerWidth / 2;
// 	let windowHalfY = window.innerHeight / 2;
// 	mouseX = (e.clientX - windowHalfX) / 100;
// 	mouseY = (e.clientY - windowHalfY) / 100;
// });
// const style = document.createElement('style');
// style.innerHTML = `
// .dg .close-button, .dg .open-button {
//   display: none !important;
// }
// `;
// document.head.appendChild(style);

// // Also hide the Color and Bloom folders if needed
// colorsFolder.domElement.style.display = 'none';
// bloomFolder.domElement.style.display = 'none';
// const clock = new THREE.Clock();
// function animate() {
// 	const elapsed = clock.getElapsedTime() * 0.3; // Slo
// 	camera.position.x += (mouseX - camera.position.x) * .05;
// 	camera.position.y += (-mouseY - camera.position.y) * 0.5;
// 	camera.lookAt(scene.position);

//     // Smooth color animations: values between 0.2 and 1.0 over time
//     uniforms.u_red.value   = 0.2 + 0.8 * (0.5 + 0.5 * Math.sin(elapsed));
//     uniforms.u_green.value = 0.2 + 0.8 * (0.5 + 0.5 * Math.sin(elapsed + 2.0));
//     uniforms.u_blue.value  = 0.2 + 0.8 * (0.5 + 0.5 * Math.sin(elapsed + 4.0));

//     // Smooth bloom animations: values between 0.0 and 0.5 over time
//     bloomPass.threshold = 0.5 * (0.5 + 0.5 * Math.sin(elapsed));
//     bloomPass.strength  = 0.5 * (0.5 + 0.5 * Math.sin(elapsed + 3.0));
//     bloomPass.radius    = 0.5 * (0.5 + 0.5 * Math.sin(elapsed + 6.0));

//     uniforms.u_time.value = clock.getElapsedTime();
//     uniforms.u_frequency.value = analyser.getAverageFrequency();


//     bloomComposer.render();
// 	requestAnimationFrame(animate);
// }
// animate();

// window.addEventListener('resize', function() {
//     camera.aspect = window.innerWidth / window.innerHeight;
//     camera.updateProjectionMatrix();
//     renderer.setSize(window.innerWidth, window.innerHeight);
// 	bloomComposer.setSize(window.innerWidth, window.innerHeight);
// });

// window.Alpine = Alpine;

// Alpine.start();
