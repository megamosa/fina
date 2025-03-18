/**
 * MagoArab CDN Integration - RequireJS Handler
 */
define([
    'jquery'
], function ($) {
    'use strict';
    
    return function (config) {
        var cdnBaseUrl = config.cdnBaseUrl || '';
        
        if (!cdnBaseUrl || !window.require) {
            return; // No CDN URL configured or RequireJS not available
        }
        
        // Store original require.load
        var originalLoad = window.requirejs.load;
        
        // Override the RequireJS load method to intercept URL loading
        window.requirejs.load = function (context, moduleId, url) {
            // Check if the URL should be replaced
            if (url && (url.indexOf('/static/') === 0 || url.indexOf('/media/') === 0)) {
                var cdnPath = '';
                if (url.indexOf('/static/') === 0) {
                    cdnPath = url.substring(8); // Remove '/static/'
                } else if (url.indexOf('/media/') === 0) {
                    cdnPath = url.substring(7); // Remove '/media/'
                }
                
                if (cdnPath) {
                    var newUrl = cdnBaseUrl.replace(/\/$/, '') + '/' + cdnPath.replace(/^\//, '');
                    console.log('MagoArab CDN: Replacing RequireJS URL', url, '->', newUrl);
                    url = newUrl;
                }
            }
            
            // Call the original load method with potentially modified URL
            return originalLoad.call(window.requirejs, context, moduleId, url);
        };
        
        // Patch require.config to replace paths
        var originalConfig = window.require.config;
        window.require.config = function (config) {
            if (config && config.paths) {
                for (var key in config.paths) {
                    var path = config.paths[key];
                    if (typeof path === 'string' && (path.indexOf('/static/') === 0 || path.indexOf('/media/') === 0)) {
                        var cdnPath = '';
                        if (path.indexOf('/static/') === 0) {
                            cdnPath = path.substring(8); // Remove '/static/'
                        } else if (path.indexOf('/media/') === 0) {
                            cdnPath = path.substring(7); // Remove '/media/'
                        }
                        
                        if (cdnPath) {
                            var newPath = cdnBaseUrl.replace(/\/$/, '') + '/' + cdnPath.replace(/^\//, '');
                            console.log('MagoArab CDN: Replacing path in config', key, path, '->', newPath);
                            config.paths[key] = newPath;
                        }
                    }
                }
            }
            
            return originalConfig.call(window.require, config);
        };
        
        // Patch text plugin if available
        if (window.require.defined('text')) {
            window.require(['text'], function (text) {
                if (text && text.load) {
                    var originalTextLoad = text.load;
                    text.load = function (name, req, onLoad, config) {
                        if (name && (name.indexOf('/static/') === 0 || name.indexOf('/media/') === 0)) {
                            var cdnPath = '';
                            if (name.indexOf('/static/') === 0) {
                                cdnPath = name.substring(8); // Remove '/static/'
                            } else if (name.indexOf('/media/') === 0) {
                                cdnPath = name.substring(7); // Remove '/media/'
                            }
                            
                            if (cdnPath) {
                                var newName = cdnBaseUrl.replace(/\/$/, '') + '/' + cdnPath.replace(/^\//, '');
                                console.log('MagoArab CDN: Replacing text plugin URL', name, '->', newName);
                                name = newName;
                            }
                        }
                        
                        return originalTextLoad.call(text, name, req, onLoad, config);
                    };
                }
            });
        }
        
        // Return public methods
        return {
            processPaths: function (paths) {
                var result = {};
                for (var key in paths) {
                    var path = paths[key];
                    if (typeof path === 'string' && (path.indexOf('/static/') === 0 || path.indexOf('/media/') === 0)) {
                        var cdnPath = '';
                        if (path.indexOf('/static/') === 0) {
                            cdnPath = path.substring(8); // Remove '/static/'
                        } else if (path.indexOf('/media/') === 0) {
                            cdnPath = path.substring(7); // Remove '/media/'
                        }
                        
                        if (cdnPath) {
                            var newPath = cdnBaseUrl.replace(/\/$/, '') + '/' + cdnPath.replace(/^\//, '');
                            result[key] = newPath;
                        } else {
                            result[key] = path;
                        }
                    } else {
                        result[key] = path;
                    }
                }
                return result;
            }
        };
    };
});