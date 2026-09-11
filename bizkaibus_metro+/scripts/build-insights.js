import { injectSpeedInsights } from '@vercel/speed-insights';
import { writeFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// Create a standalone module that can be included in the HTML
const code = `
// Vercel Speed Insights
(function() {
  ${injectSpeedInsights.toString()}
  
  // Inject on page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      injectSpeedInsights();
    });
  } else {
    injectSpeedInsights();
  }
})();
`;

const outputPath = join(__dirname, '..', 'js', 'speed-insights.js');
writeFileSync(outputPath, code, 'utf-8');
console.log('✓ Speed Insights script built successfully');
