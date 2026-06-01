/** @type {import('tailwindcss').Config} */
module.exports = {
	content: [
		'./src/**/*.{js,jsx}',
		'./templates/**/*.php',
	],
	darkMode: 'class',
	theme: {
		extend: {
			colors: {
				ollama: {
					primary: 'var(--ollama-primary, #6366f1)',
				},
			},
		},
	},
	plugins: [],
	prefix: 'oac-',
};
