import assert from 'node:assert/strict';

export async function assertControlContrast(locator, label, {minimum = 4.5, placeholder = false, focus = false} = {}) {
  await locator.waitFor({state: 'visible'});
  const result = await locator.evaluate((el, args) => {
    const context = document.createElement('canvas').getContext('2d', {willReadFrequently: true});
    const rgb = color => {
      context.clearRect(0, 0, 1, 1);
      context.fillStyle = color;
      context.fillRect(0, 0, 1, 1);
      const [r, g, b, a] = context.getImageData(0, 0, 1, 1).data;
      return [r, g, b, a / 255];
    };
    const blend = ([r, g, b, alpha], background) =>
      [r, g, b].map((channel, i) => channel * alpha + background[i] * (1 - alpha));
    const surface = el => {
      const ancestors = [];
      for (let node = el; node; node = node.parentElement) ancestors.unshift(node);
      return ancestors.reduce((back, node) => blend(rgb(getComputedStyle(node).backgroundColor), back), [255, 255, 255]);
    };
    const luminance = channels => channels.map(channel => {
      const value = channel / 255;
      return value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4;
    }).reduce((total, value, index) => total + value * [.2126, .7152, .0722][index], 0);
    const ratio = (front, back) => {
      const a = luminance(blend(rgb(front), back));
      const b = luminance(back);
      return (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
    };
    const style = getComputedStyle(el);
    if (!args.focus) {
      const color = args.placeholder ? getComputedStyle(el, '::placeholder').color : style.color;
      return {ratio: ratio(color, surface(el))};
    }
    const adjacent = surface(el.parentElement);
    const shadow = style.boxShadow.match(/rgba?\([^)]*\)/)?.[0];
    return {
      ratio: Math.max(ratio(style.outlineColor, adjacent), shadow ? ratio(shadow, adjacent) : 0),
      visible: el.matches(':focus-visible') && parseFloat(style.outlineWidth) >= 2 && style.outlineStyle !== 'none',
    };
  }, {placeholder, focus});
  assert(result.ratio >= minimum, `${label}: ${result.ratio.toFixed(2)}:1, expected at least ${minimum}:1`);
  if (focus) assert(result.visible, `${label}: no visible keyboard focus outline`);
  return result.ratio;
}
