/**
 * @copyright 2009-2017 Vanilla Forums Inc.
 * @license MIT
 */

const path = require("path");
const gulp = require("gulp");
const livereload = require("gulp-livereload");
const argv = require("yargs").argv;
const chalk = require('chalk');

const utility = require("../../utility");

const buildJs = require("./build.javascript");
const buildStyles = require("./build.stylesheets");
const buildAssets = require("./build.assets");

const addonpath = process.cwd();
const options = JSON.parse(argv.options);

gulp.task("build:js", () => {
    return buildJs(addonpath, options);
});

gulp.task("build:styles", () => {
    return buildStyles(addonpath, options);
});

gulp.task("build:assets", () => {
    return buildAssets(addonpath, options);
});

gulp.task("build", ["build:js", "build:assets", "build:styles"]);

gulp.task("watch", ["build"], () => {
    livereload.listen();

    gulp.watch(
        [
            path.resolve(addonpath, "design/*.css"),
            path.resolve(addonpath, "design/images/**/*"),
            path.resolve(addonpath, "js/*.js"),
            path.resolve(addonpath, "views/**/*"),
        ],
        file => {
            return livereload.changed(file.path);
        }
    );

    gulp.watch(path.resolve(addonpath, "src/**/*.scss"), ["build:styles"]);
    gulp.watch(path.resolve(addonpath, "src/**/*.js"), ["build:js"]);
    gulp.watch(path.resolve(addonpath, "design/images/**/*"), ["build:assets"]);

    console.log("\n" + chalk.green('Watching for changes in src files...'));
});


const taskToExecute = options.watch ? "watch" : "build";

gulp.start(taskToExecute);
