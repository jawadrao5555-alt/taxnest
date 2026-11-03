(() => {
    // Presentation only. This marker never grants authentication or capability.
    if (/TaxNest(?:POS|DI|FBRPos)App\//.test(navigator.userAgent)) {
        document.documentElement.classList.add('tn-native-app');
    }
})();
