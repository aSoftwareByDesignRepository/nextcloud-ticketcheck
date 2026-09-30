/**
 * ESLint for TicketCheck vanilla JS (Nextcloud globals, no bundler).
 * Run: npm run lint
 */
module.exports = {
	root: true,
	env: {
		browser: true,
		es2022: true,
	},
	globals: {
		OC: 'readonly',
		t: 'readonly',
		n: 'readonly',
		OCA: 'readonly',
	},
	parserOptions: {
		ecmaVersion: 'latest',
		sourceType: 'script',
	},
	ignorePatterns: ['node_modules/', 'vendor/', 'dist/', 'build/'],
	rules: {
		'no-eval': 'error',
		'no-implied-eval': 'error',
		'no-new-func': 'error',
		eqeqeq: ['warn', 'always', { null: 'ignore' }],
		'no-unused-vars': [
			'warn',
			{
				argsIgnorePattern: '^_',
				varsIgnorePattern: '^_',
				caughtErrors: 'none',
			},
		],
		'no-restricted-globals': [
			'warn',
			{ name: 'event', message: 'Use the event parameter or local variable instead of the implicit global.' },
		],
		curly: ['warn', 'multi-line'],
	},
};
