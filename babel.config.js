module.exports = {
	plugins: [
		'@babel/plugin-syntax-dynamic-import',
	],
	presets: [
		[
			'@babel/preset-env',
			{
                "targets": "> 5%, not dead",
				useBuiltIns: false,
				modules: 'auto',
			},
		],
	],
}
