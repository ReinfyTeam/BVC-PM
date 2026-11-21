<?php

/*
 *
 *  ____           _            __           _____
 * |  _ \    ___  (_)  _ __    / _|  _   _  |_   _|   ___    __ _   _ __ ___
 * | |_) |  / _ \ | | | '_ \  | |_  | | | |   | |    / _ \  / _` | | '_ ` _ \
 * |  _ <  |  __/ | | | | | | |  _| | |_| |   | |   |  __/ | (_| | | | | | | |
 * |_| \_\  \___| |_| |_| |_| |_|    \__, |   |_|    \___|  \__,_| |_| |_| |_|
 *                                   |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Zuri attempts to enforce "vanilla Minecraft" mechanics, as well as preventing
 * players from abusing weaknesses in Minecraft or its protocol, making your server
 * more safe. Organized in different sections, various checks are performed to test
 * players doing, covering a wide range including flying and speeding, fighting
 * hacks, fast block breaking and nukers, inventory hacks, chat spam and other types
 * of malicious behaviour.
 *
 * @author ReinfyTeam
 * @link https://github.com/ReinfyTeam/
 *
 *
 */

declare(strict_types=1);

chdir(dirname(__DIR__));

const PLUGIN_NAMESPACE = "RodsSMP\\SMPCore";
const LIBRARY_NAMESPACE = PLUGIN_NAMESPACE . "\\libs";

function fail(string $message) : never {
	fwrite(STDERR, "Build failed: " . $message . PHP_EOL);
	exit(1);
}

function parseOutputPath(array $arguments) : string {
	$output = getcwd() . DIRECTORY_SEPARATOR . "SMPCore.phar";
	$positionalOutput = null;
	$optionOutput = false;

	for ($index = 0, $count = count($arguments); $index < $count; $index++) {
		$argument = $arguments[$index];

		if ($argument === "-o" || $argument === "--output") {
			$index++;
			if ($index >= $count || $arguments[$index] === "") {
				fail($argument . " requires an output path");
			}
			if ($positionalOutput !== null || $optionOutput) {
				fail("Output path was specified more than once");
			}
			$output = $arguments[$index];
			$optionOutput = true;
			continue;
		}

		if (str_starts_with($argument, "--output=")) {
			$value = substr($argument, strlen("--output="));
			if ($value === "") {
				fail("--output requires an output path");
			}
			if ($positionalOutput !== null || $optionOutput) {
				fail("Output path was specified more than once");
			}
			$output = $value;
			$optionOutput = true;
			continue;
		}

		if (str_starts_with($argument, "-")) {
			fail("Unknown option: " . $argument);
		}
		if ($positionalOutput !== null) {
			fail("Unexpected argument: " . $argument);
		}

		$positionalOutput = $argument;
		$output = $argument;
	}

	return $output;
}

function copyDirectory(string $source, string $destination) : void {
	if (!is_dir($source)) {
		fail("Directory does not exist: " . $source);
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ($iterator as $item) {
		$relative = substr($item->getPathname(), strlen($source) + 1);
		$target = $destination . DIRECTORY_SEPARATOR . $relative;

		if ($item->isDir()) {
			if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
				fail("Unable to create directory: " . $target);
			}
		} elseif ($item->isFile()) {
			if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
				fail("Unable to create directory: " . dirname($target));
			}
			if (!copy($item->getPathname(), $target)) {
				fail("Unable to copy: " . $item->getPathname());
			}
		}
	}
}

function removeDirectory(string $directory) : void {
	if (!is_dir($directory)) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ($iterator as $item) {
		if ($item->isDir()) {
			rmdir($item->getPathname());
		} else {
			unlink($item->getPathname());
		}
	}

	rmdir($directory);
}

/**
 * @param array<string, string> $namespaceMap
 */
function prefixNamespace(string $name, array $namespaceMap) : string {
	$leadingSlash = str_starts_with($name, "\\");
	$withoutSlash = ltrim($name, "\\");

	foreach ($namespaceMap as $namespace => $replacement) {
		if ($withoutSlash === $namespace || str_starts_with($withoutSlash, $namespace . "\\")) {
			return ($leadingSlash ? "\\" : "") . $replacement . substr($withoutSlash, strlen($namespace));
		}
	}

	return $name;
}

/**
 * @param array<string, string> $namespaceMap
 */
function rewritePhp(string $source, array $namespaceMap) : string {
	$tokens = token_get_all($source);
	$output = "";

	foreach ($tokens as $token) {
		if (is_string($token)) {
			$output .= $token;
			continue;
		}

		[$id, $text] = $token;
		if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
			$output .= prefixNamespace($text, $namespaceMap);
		} else {
			$output .= $text;
		}
	}

	return $output;
}

/**
 * Read Composer's installed package metadata, supporting both Composer 1's
 * list format and Composer 2's { packages: [...] } format.
 *
 * @return array<string, array<string, mixed>> Packages indexed by normalized name.
 */
function loadInstalledPackages(string $vendorDirectory) : array {
	$installedPath = $vendorDirectory . "/composer/installed.json";
	if (!is_file($installedPath)) {
		fail("Composer installed.json is missing: " . $installedPath);
	}

	$contents = file_get_contents($installedPath);
	if ($contents === false) {
		fail("Unable to read Composer installed.json: " . $installedPath);
	}

	$installed = json_decode($contents, true);
	if (!is_array($installed)) {
		fail("Composer installed.json contains invalid JSON");
	}

	$packageList = $installed["packages"] ?? $installed;
	if (!is_array($packageList)) {
		fail("Composer installed.json does not contain a valid package list");
	}

	$packages = [];
	foreach ($packageList as $package) {
		if (!is_array($package)) {
			continue;
		}
		$name = strtolower((string) ($package["name"] ?? ""));
		if ($name !== "") {
			$packages[$name] = $package;
		}
	}

	return $packages;
}

/**
 * Resolve the complete production dependency tree from the root composer.json.
 * require-dev packages are intentionally excluded unless a production package
 * also requires them.
 *
 * Package names are used only to resolve Composer's dependency graph. PHP
 * namespaces are discovered separately from each package's autoload metadata.
 *
 * @param array<string, array<string, mixed>> $installedPackages
 * @return array<string, array<string, mixed>>
 */
function resolveRuntimePackages(array $rootComposer, array $installedPackages) : array {
	$queue = [];
	foreach ($rootComposer["require"] ?? [] as $packageName => $_constraint) {
		$queue[] = strtolower((string) $packageName);
	}

	$runtimePackages = [];
	while ($queue !== []) {
		$packageName = array_shift($queue);
		if (!is_string($packageName) || $packageName === "" || isset($runtimePackages[$packageName])) {
			continue;
		}

		// Platform requirements (php, ext-*, lib-*) are not installed library packages.
		if (!isset($installedPackages[$packageName])) {
			continue;
		}

		$package = $installedPackages[$packageName];
		$runtimePackages[$packageName] = $package;

		foreach ($package["require"] ?? [] as $dependencyName => $_constraint) {
			$dependencyName = strtolower((string) $dependencyName);
			if ($dependencyName !== "" && !isset($runtimePackages[$dependencyName])) {
				$queue[] = $dependencyName;
			}
		}
	}

	return $runtimePackages;
}

/**
 * @param array<string, array<string, mixed>> $runtimePackages
 * @return array<string, string> Package name => absolute installation directory.
 */
function loadPackageInstallPaths(string $vendorDirectory, array $runtimePackages) : array {
	$paths = [];
	$installedPhp = $vendorDirectory . "/composer/installed.php";

	if (is_file($installedPhp)) {
		/** @var mixed $installedData */
		$installedData = require $installedPhp;
		$versions = is_array($installedData) ? ($installedData["versions"] ?? []) : [];

		if (is_array($versions)) {
			foreach ($runtimePackages as $packageName => $_package) {
				$installPath = $versions[$packageName]["install_path"] ?? null;
				if (is_string($installPath) && is_dir($installPath)) {
					$paths[$packageName] = realpath($installPath) ?: $installPath;
				}
			}
		}
	}

	// Composer's default vendor layout is a safe fallback for older metadata.
	foreach ($runtimePackages as $packageName => $_package) {
		if (isset($paths[$packageName])) {
			continue;
		}

		$fallback = $vendorDirectory . DIRECTORY_SEPARATOR
			. str_replace("/", DIRECTORY_SEPARATOR, $packageName);
		if (is_dir($fallback)) {
			$paths[$packageName] = realpath($fallback) ?: $fallback;
		}
	}

	return $paths;
}

function normalizePath(string $path) : string {
	$path = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $path);
	return rtrim($path, DIRECTORY_SEPARATOR);
}

function pathIsInside(string $file, string $directory) : bool {
	$filePath = realpath($file) ?: $file;
	$directoryPath = realpath($directory) ?: $directory;
	$filePath = normalizePath($filePath);
	$directoryPath = normalizePath($directoryPath);

	if (DIRECTORY_SEPARATOR === "\\") {
		$filePath = strtolower($filePath);
		$directoryPath = strtolower($directoryPath);
	}

	return $filePath === $directoryPath
		|| str_starts_with($filePath, $directoryPath . DIRECTORY_SEPARATOR);
}

/**
 * @param array<string, string> $packagePaths
 */
function belongsToRuntimePackage(string $file, array $packagePaths) : bool {
	foreach ($packagePaths as $packagePath) {
		if (pathIsInside($file, $packagePath)) {
			return true;
		}
	}
	return false;
}

/**
 * Collect package autoload rules and Composer's generated classmap.
 *
 * In particular, classmap-only libraries are supported. Their PHP namespaces
 * are derived from Composer's generated class => file map, not from the
 * Composer package name. This covers packages such as rodssmp/apibossbar,
 * whose composer.json declares autoload.classmap instead of PSR-4/PSR-0.
 *
 * @return array{
 *   map: array<string, string>,
 *   psr-4: array<string, array<int, string>>,
 *   psr-0: array<string, array<int, string>>,
 *   classmap: array<string, string>,
 *   packages: array<string, array<string, mixed>>
 * }
 */
function loadLibraryAutoload(string $vendorDirectory) : array {
	$composerJsonPath = getcwd() . DIRECTORY_SEPARATOR . "composer.json";
	if (!is_file($composerJsonPath)) {
		fail("composer.json not found: " . $composerJsonPath);
	}

	$composerJsonContents = file_get_contents($composerJsonPath);
	if ($composerJsonContents === false) {
		fail("Unable to read composer.json: " . $composerJsonPath);
	}

	$rootComposer = json_decode($composerJsonContents, true);
	if (!is_array($rootComposer)) {
		fail("composer.json contains invalid JSON");
	}

	$installedPackages = loadInstalledPackages($vendorDirectory);
	$runtimePackages = resolveRuntimePackages($rootComposer, $installedPackages);
	if ($runtimePackages === []) {
		fwrite(STDERR, "Warning: No installed production Composer dependencies were found." . PHP_EOL);
	}

	$packagePaths = loadPackageInstallPaths($vendorDirectory, $runtimePackages);
	$namespaceMap = [];
	$sources = ["psr-4" => [], "psr-0" => []];

	// PSR-4 and PSR-0 are namespace-prefix based. Read them from each package's
	// own metadata, then use Composer's generated maps to get absolute paths.
	$composerAutoloadFiles = [
		"psr-4" => $vendorDirectory . "/composer/autoload_psr4.php",
		"psr-0" => $vendorDirectory . "/composer/autoload_namespaces.php"
	];
	$generatedMaps = [];
	foreach ($composerAutoloadFiles as $type => $file) {
		if (is_file($file)) {
			$map = require $file;
			$generatedMaps[$type] = is_array($map) ? $map : [];
		} else {
			$generatedMaps[$type] = [];
		}
	}

	foreach ($runtimePackages as $package) {
		$autoload = $package["autoload"] ?? [];
		if (!is_array($autoload)) {
			continue;
		}

		foreach (["psr-4", "psr-0"] as $type) {
			foreach ($autoload[$type] ?? [] as $prefix => $_relativeDirectories) {
				if (!is_string($prefix)) {
					continue;
				}

				$prefix = trim($prefix, "\\");
				// Empty fallback prefixes do not identify a namespace. Composer's
				// classmap entries are used for concrete classes instead.
				if ($prefix === "" || $prefix === trim(PLUGIN_NAMESPACE, "\\")) {
					continue;
				}

				$generated = $generatedMaps[$type];
				$directories = $generated[$prefix . "\\"] ?? $generated[$prefix] ?? [];
				if (is_string($directories)) {
					$directories = [$directories];
				}
				if (!is_array($directories)) {
					$directories = [];
				}

				$directories = array_values(array_filter(
					$directories,
					static fn (mixed $directory) : bool => is_string($directory) && $directory !== ""
				));

				if ($directories === []) {
					continue;
				}

				$namespaceMap[$prefix] = LIBRARY_NAMESPACE . "\\" . $prefix;
				$sources[$type][$prefix] = array_values(array_unique(array_merge(
					$sources[$type][$prefix] ?? [],
					$directories
				)));
			}
		}
	}

	// Composer's classmap is the authoritative class => file list for classmap
	// packages, including libraries which do not declare PSR-4 or PSR-0.
	$classMapPath = $vendorDirectory . "/composer/autoload_classmap.php";
	if (!is_file($classMapPath)) {
		fail("Composer autoload_classmap.php is missing: " . $classMapPath);
	}

	$composerClassMap = require $classMapPath;
	if (!is_array($composerClassMap)) {
		fail("Composer autoload_classmap.php does not return a valid class map");
	}

	$libraryClassMap = [];
	foreach ($composerClassMap as $class => $file) {
		if (!is_string($class) || !is_string($file) || !is_file($file)) {
			continue;
		}
		if (!belongsToRuntimePackage($file, $packagePaths)) {
			continue;
		}

		$separator = strrpos($class, "\\");
		if ($separator === false) {
			// Global classes cannot be namespace-prefixed safely. Leave them
			// untouched rather than creating a broken or collision-prone alias.
			continue;
		}

		$namespace = substr($class, 0, $separator);
		if ($namespace === "" || $namespace === trim(PLUGIN_NAMESPACE, "\\")) {
			continue;
		}

		$namespaceMap[$namespace] = LIBRARY_NAMESPACE . "\\" . $namespace;
		$libraryClassMap[$class] = realpath($file) ?: $file;
	}

	// The longest matching namespace must be rewritten first. This matters for
	// nested namespaces and classmap namespaces below broader PSR prefixes.
	uksort($namespaceMap, static fn (string $a, string $b) : int => strlen($b) <=> strlen($a));

	return [
		"map" => $namespaceMap,
		"psr-4" => $sources["psr-4"],
		"psr-0" => $sources["psr-0"],
		"classmap" => $libraryClassMap,
		"packages" => $runtimePackages
	];
}

function getLibraryDirectory(string $sourceDirectory) : string {
	return $sourceDirectory . DIRECTORY_SEPARATOR
		. str_replace("\\", DIRECTORY_SEPARATOR, LIBRARY_NAMESPACE);
}

/**
 * @param array<string, string> $namespaceMap
 */
function copyLibraryNamespace(
	string $temporarySourceDirectory,
	string $namespace,
	array $directories,
	string $autoloadType,
	array $namespaceMap
) : void {
	$relativeNamespace = str_replace("\\", DIRECTORY_SEPARATOR, trim($namespace, "\\"));
	$target = getLibraryDirectory($temporarySourceDirectory) . DIRECTORY_SEPARATOR . $relativeNamespace;

	foreach (array_unique($directories) as $directory) {
		// PSR-4 maps the namespace prefix directly to the directory contents.
		// PSR-0 maps the base directory, with the namespace path nested inside it.
		$source = $directory;
		if ($autoloadType === "psr-0") {
			$source = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativeNamespace;
		}

		if (!is_dir($source)) {
			fwrite(
				STDERR,
				"Warning: Skipping {$autoloadType} namespace {$namespace}; mapped directory not found: {$source}" . PHP_EOL
			);
			continue;
		}

		copyPhpSource($source, $target, $namespaceMap);
	}
}

/**
 * Copy a classmap entry into its namespace-derived path inside the plugin.
 * Multiple classmap symbols may point to the same source file; each target is
 * retained because the plugin's PSR-4 loader resolves classes by class name.
 *
 * @param array<string, string> $namespaceMap
 */
function copyClassMapEntry(
	string $class,
	string $sourceFile,
	string $temporarySourceDirectory,
	array $namespaceMap
) : void {
	$target = getLibraryDirectory($temporarySourceDirectory)
		. DIRECTORY_SEPARATOR . str_replace("\\", DIRECTORY_SEPARATOR, $class) . ".php";

	if (is_file($target)) {
		return;
	}

	if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
		fail("Unable to create directory: " . dirname($target));
	}

	$contents = file_get_contents($sourceFile);
	if ($contents === false) {
		fail("Unable to read classmap source: " . $sourceFile);
	}

	if (file_put_contents($target, rewritePhp($contents, $namespaceMap)) === false) {
		fail("Unable to write classmap target: " . $target);
	}
}

/**
 * @param array<string, string> $namespaceMap
 */
function copyPhpSource(string $source, string $destination, array $namespaceMap) : void {
	if (!is_dir($source)) {
		fail("Source directory does not exist: " . $source);
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
	);

	foreach ($iterator as $item) {
		if (!$item->isFile()) {
			continue;
		}

		$relative = substr($item->getPathname(), strlen($source) + 1);
		$target = $destination . DIRECTORY_SEPARATOR . $relative;
		if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
			fail("Unable to create directory: " . dirname($target));
		}

		$contents = file_get_contents($item->getPathname());
		if ($contents === false) {
			fail("Unable to read: " . $item->getPathname());
		}

		if (str_ends_with(strtolower($item->getFilename()), ".php")) {
			$contents = rewritePhp($contents, $namespaceMap);
		}

		if (file_put_contents($target, $contents) === false) {
			fail("Unable to write: " . $target);
		}
	}
}

$vendor = getcwd() . DIRECTORY_SEPARATOR . "vendor";
$source = getcwd() . DIRECTORY_SEPARATOR . "src";
$resources = getcwd() . DIRECTORY_SEPARATOR . "resources";
$output = parseOutputPath(array_slice($argv, 1));

if (Phar::canWrite() === false) {
	fail("PHAR creation is disabled; run PHP with -d phar.readonly=0");
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "smpcore-" . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0777, true)) {
	fail("Unable to create temporary directory: " . $temporary);
}

try {
	$libraryAutoload = loadLibraryAutoload($vendor);
	$namespaceMap = $libraryAutoload["map"];

	copyDirectory($resources, $temporary . DIRECTORY_SEPARATOR . "resources");
	copyPhpSource($source, $temporary . DIRECTORY_SEPARATOR . "src", $namespaceMap);

	foreach (["psr-4", "psr-0"] as $autoloadType) {
		foreach ($libraryAutoload[$autoloadType] as $namespace => $directories) {
			copyLibraryNamespace(
				$temporary . DIRECTORY_SEPARATOR . "src",
				$namespace,
				$directories,
				$autoloadType,
				$namespaceMap
			);
		}
	}

	// Classmap-only packages are not represented in Composer's PSR-4/PSR-0
	// prefix files, so copy their classes explicitly from Composer's class map.
	foreach ($libraryAutoload["classmap"] as $class => $file) {
		copyClassMapEntry($class, $file, $temporary . DIRECTORY_SEPARATOR . "src", $namespaceMap);
	}

	$plugin = getcwd() . DIRECTORY_SEPARATOR . "plugin.yml";
	if (!is_file($plugin) || !copy($plugin, $temporary . DIRECTORY_SEPARATOR . "plugin.yml")) {
		fail("Unable to copy plugin.yml");
	}

	$outputDirectory = dirname($output);
	if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
		fail("Unable to create output directory: " . $outputDirectory);
	}
	if (is_file($output)) {
		unlink($output);
	}

	$phar = new Phar($output);
	$phar->buildFromDirectory($temporary);
	$phar->setStub($phar->createDefaultStub());

	$packageCount = count($libraryAutoload["packages"]);
	$namespaceCount = count($namespaceMap);
	$classCount = count($libraryAutoload["classmap"]);
	echo "Built " . $output . PHP_EOL;
	echo "Bundled {$packageCount} production Composer package(s), {$namespaceCount} namespace mapping(s), and {$classCount} classmap class(es)." . PHP_EOL;
} finally {
	removeDirectory($temporary);
}
