interface StringToStringObject {
    [key: string]: string;
}

interface BuildOptions {
    buildOptions: {
        process: 'legacy' | '1.0';
        cssTool: 'scss' | 'less';
        entries: StringToStringObject | string[];
        exports: StringToStringObject | string[];
    };
    vanillaDirectory: string;
    rootDirectories: string[];
    requiredDirectories: string[];
    watch: boolean;
    verbose: boolean;
}
