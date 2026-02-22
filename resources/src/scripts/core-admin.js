window.addEventListener('load', (event) => {

    window.andromedaAutoloadDependencies = [];

    window.breakpoints = {
        sm: 576,
        md: 768,
        lg: 992,
    };
    
    const exoAutoload = [
        {
            script: 'ai-setup',
            className: 'ExoAiSetup',
            onload: () => {
                if (
                    typeof window.ExoAiSetup !== 'undefined' &&
                    window.ExoAiSetup !== null &&
                    typeof window.ExoAiSetup.init === 'function'
                ) {
                    window.ExoAiSetup.init();
                }
            },
            loadWhen: () => {
                return (
                    typeof document.querySelector('#exmoau_ai_behaviour_mode') !== 'undefined' &&
                    document.querySelector('#exmoau_ai_behaviour_mode')
                );
            }
        },
        {
            script: 'library',
            className: 'ExoAuthorLibrary',
            onload: () => {
                if (
                    typeof window.ExoAuthorLibrary !== 'undefined' &&
                    window.ExoAuthorLibrary !== null &&
                    typeof window.ExoAuthorLibrary.init === 'function'
                ) {
                    window.ExoAuthorLibrary.init();
                }
            },
            loadWhen: () => {
                return (
                    typeof document.querySelector('[data-exmoau-library]') !== 'undefined' &&
                    document.querySelector('[data-exmoau-library]')
                );
            }
        },
        {
            script: 'library-welcome',
            className: 'ExoLibraryWelcomeModal',
            onload: () => {
                if (
                    typeof window.ExoLibraryWelcomeModal !== 'undefined' &&
                    window.ExoLibraryWelcomeModal !== null &&
                    typeof window.ExoLibraryWelcomeModal.init === 'function'
                ) {
                    window.ExoLibraryWelcomeModal.init();
                }
            },
            loadWhen: () => {
                const config = (window.ExMomentAuthorAdminConfig || {});
                const libraryConfig = (config.library || {});

                return (libraryConfig.hasContent === false);
            }
        },
        {
            script: 'jobs-meta',
            className: 'ExoJobsMeta',
            onload: () => {
                if (
                    typeof window.ExoJobsMeta !== 'undefined' &&
                    window.ExoJobsMeta !== null &&
                    typeof window.ExoJobsMeta.init === 'function'
                ) {
                    window.ExoJobsMeta.init();
                }
            },
            loadWhen: () => {
                return (
                    typeof document.querySelector('[data-exmoau-job-meta]') !== 'undefined' &&
                    document.querySelector('[data-exmoau-job-meta]')
                );
            }
        },
        {
            script: 'exo-log',
            className: 'ExoLog',
            onload: () => {
                if (
                    typeof window.ExoLog !== 'undefined' &&
                    window.ExoLog !== null &&
                    typeof window.ExoLog.init === 'function'
                ) {
                    window.ExoLog.init();
                }
            },
            loadWhen: () => {
                return (
                    typeof document.querySelector('.exo-log') !== 'undefined' &&
                    document.querySelector('.exo-log')
                );
            }
        },
    ];

    const exoConfig = window.ExMomentAuthorAdminConfig || { scripts: {} };

    window.ExMomentAuthorAdminConfig = exoConfig;

    if (typeof exoConfig.scripts === 'undefined') {
        exoConfig.scripts = {
            dir: '',
            dependenciesDir: '',
            version: '',
        };
    }

    window.scriptsDir = exoConfig.scripts.dir;

    exoAutoload.forEach((element) => {
        if (
            typeof element.loadWhen === 'function' &&
            element.loadWhen() 
        ) { 
            if (
                typeof element.worksWith !== 'undefined' &&
                element.worksWith !== null &&
                element.worksWith.length > 0
            ) {
                window.andromedaAutoloadDependencies.push({
                    element: element,
                    loaded: 0,
                });
                element.worksWith.forEach((dependency) => {
                    window.exoAuthorLoadDependencyScript(dependency.script, (window.andromedaAutoloadDependencies.length - 1));
                });
            } else { 
                window.exoAuthorLoadScript(element);
            }
        }
    });
});

window.exoAuthorLoadScript = (element) => {
    const exoConfig = window.ExMomentAuthorAdminConfig;
    if (!exoConfig || !exoConfig.scripts) { return; }
    const script = document.createElement('script');
    script.src = `${exoConfig.scripts.dir}${element.script}.js?ver=${exoConfig.scripts.version}`;
    script.async = 'async';
    script.onload = () => {
        if (typeof window[element.className] === 'object') {
            if (typeof element.onload !== 'undefined') {
                element.onload();
            }
        }
    };
    document.body.appendChild(script);
}

window.exoAuthorLoadDependencyScript = (dependency, id) => {
    const exoConfig = window.ExMomentAuthorAdminConfig;
    if (!exoConfig || !exoConfig.scripts) { return; }
    const script = document.createElement('script');
    script.src = `${exoConfig.scripts.dependenciesDir}${dependency}.js?ver=${exoConfig.scripts.version}`;
    script.async = 'async';
    script.onload = () => {
        window.andromedaAutoloadDependencies[id].loaded += 1;
        if (window.andromedaAutoloadDependencies[id].loaded >= window.andromedaAutoloadDependencies[id].element.worksWith.length) {
            window.exoAuthorLoadScript(window.andromedaAutoloadDependencies[id].element);
        }
    };
    document.body.appendChild(script);
}
