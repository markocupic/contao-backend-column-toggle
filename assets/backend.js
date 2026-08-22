import {Application} from '@hotwired/stimulus';
import {definitionForModuleAndIdentifier, identifierForContextKey} from '@hotwired/stimulus-webpack-helpers';

import './styles/scss/styles.scss';

// Start the Stimulus application
const application = Application.start();
application.debug = process.env.NODE_ENV === 'development';

// Register all controllers with `cbct--` prefix
const context = require.context(
    '@symfony/stimulus-bridge/lazy-controller-loader!./controllers',
    true,
    /\.[jt]sx?$/
);

application.load(context.keys()
    .map((key) => {
        const identifier = identifierForContextKey(key);
        if (identifier) {
            return definitionForModuleAndIdentifier(context(key), `cbct--${identifier}`);
        }
    }).filter((value) => value)
);
