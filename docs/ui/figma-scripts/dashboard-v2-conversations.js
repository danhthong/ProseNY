// Figma use_figma script: Dashboard v2 conversation cards
// Paste inner body into use_figma code parameter

await figma.loadFontAsync({ family: 'Inter', style: 'Regular' });
await figma.loadFontAsync({ family: 'Inter', style: 'Medium' });
await figma.loadFontAsync({ family: 'Inter', style: 'Semi Bold' });
await figma.loadFontAsync({ family: 'Inter', style: 'Bold' });

const main = await figma.getNodeByIdAsync('148:48');
if (!main || main.type !== 'FRAME') return { error: 'Main not found' };

const vars = await figma.variables.getLocalVariablesAsync();
const v = {};
for (const item of vars) v[item.name] = item;

function bindFill(node, varName) {
  const variable = v[varName];
  if (!variable || !node.fills || node.fills === figma.mixed || !node.fills.length) return;
  const paint = figma.variables.setBoundVariableForPaint({ ...node.fills[0], type: 'SOLID' }, 'color', variable);
  node.fills = [paint];
}

function bindStroke(node, varName) {
  const variable = v[varName];
  if (!variable) return;
  const stroke = figma.variables.setBoundVariableForPaint({ type: 'SOLID', color: { r: 0.9, g: 0.91, b: 0.92 } }, 'color', variable);
  node.strokes = [stroke];
}

function makeText(chars, size, weight) {
  const t = figma.createText();
  t.fontName = { family: 'Inter', style: weight };
  t.characters = chars;
  t.fontSize = size;
  t.textAutoResize = 'WIDTH_AND_HEIGHT';
  return t;
}

function makeWrappedText(chars, size, weight, width) {
  const t = makeText(chars, size, weight);
  t.textAutoResize = 'HEIGHT';
  t.resize(width, t.height);
  return t;
}

function makeProgressBar(pct, width) {
  const track = figma.createFrame();
  track.name = 'Progress track';
  track.resize(width, 8);
  track.cornerRadius = 999;
  track.fills = [{ type: 'SOLID', color: { r: 0.945, g: 0.961, b: 0.976 } }];
  bindFill(track, 'bg/muted');
  track.layoutMode = 'NONE';
  const fill = figma.createRectangle();
  fill.resize(Math.max(8, Math.round(width * pct / 100)), 8);
  fill.cornerRadius = 999;
  fill.fills = [{ type: 'SOLID', color: { r: 0.31, g: 0.275, b: 0.898 } }];
  bindFill(fill, 'brand/solid');
  track.appendChild(fill);
  return track;
}

function makePill(label, state) {
  const pill = figma.createAutoLayout('HORIZONTAL', {
    name: 'Pill',
    paddingLeft: 12,
    paddingRight: 12,
    paddingTop: 4,
    paddingBottom: 4,
    cornerRadius: 999,
    strokeWeight: 1,
  });
  pill.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
  pill.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
  if (state === 'completed') {
    pill.fills = [{ type: 'SOLID', color: { r: 0.925, g: 0.992, b: 0.961 } }];
    pill.strokes = [{ type: 'SOLID', color: { r: 0.655, g: 0.953, b: 0.816 } }];
  } else if (state === 'current') {
    pill.fills = [{ type: 'SOLID', color: { r: 0.933, g: 0.945, b: 1 } }];
    pill.strokes = [{ type: 'SOLID', color: { r: 0.635, g: 0.608, b: 0.98 } }];
  }
  const t = makeText(label, 12, 'Medium');
  bindFill(t, state === 'current' ? 'text/brand' : 'text/secondary');
  pill.appendChild(t);
  return pill;
}

function makeMiniPanel(titleText) {
  const panel = figma.createAutoLayout('VERTICAL', {
    name: titleText,
    paddingLeft: 16,
    paddingRight: 16,
    paddingTop: 16,
    paddingBottom: 16,
    itemSpacing: 10,
    cornerRadius: 8,
    strokeWeight: 1,
  });
  panel.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
  panel.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
  bindFill(panel, 'bg/surface');
  bindStroke(panel, 'border/subtle');
  const h = makeText(titleText, 16, 'Semi Bold');
  bindFill(h, 'text/primary');
  panel.appendChild(h);
  return panel;
}

function makeBtn(label, primary) {
  const btn = figma.createAutoLayout('HORIZONTAL', {
    name: label,
    paddingLeft: primary ? 16 : 12,
    paddingRight: primary ? 16 : 12,
    paddingTop: primary ? 10 : 8,
    paddingBottom: primary ? 10 : 8,
    cornerRadius: 8,
    strokeWeight: primary ? 0 : 1,
  });
  if (primary) {
    btn.fills = [{ type: 'SOLID', color: { r: 0.31, g: 0.275, b: 0.898 } }];
    bindFill(btn, 'brand/solid');
  } else {
    btn.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
    btn.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
  }
  const t = makeText(label, 13, 'Medium');
  bindFill(t, primary ? 'brand/on-solid' : 'text/secondary');
  btn.appendChild(t);
  return btn;
}

function makeConversationCard(data) {
  const card = figma.createAutoLayout('VERTICAL', {
    name: 'Conversation card',
    paddingLeft: 16,
    paddingRight: 16,
    paddingTop: 16,
    paddingBottom: 16,
    itemSpacing: 16,
    cornerRadius: 12,
    strokeWeight: 1,
  });
  card.fills = [{ type: 'SOLID', color: { r: 0.973, g: 0.98, b: 0.988 } }];
  card.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
  bindFill(card, 'bg/muted');
  bindStroke(card, 'border/default');

  const headerRow = figma.createAutoLayout('HORIZONTAL', { name: 'Header', itemSpacing: 16 });
  const mainCol = figma.createAutoLayout('VERTICAL', { name: 'Main', itemSpacing: 4 });
  const convTitle = makeText(data.title, 15, 'Semi Bold');
  bindFill(convTitle, 'text/brand');
  const preview = makeWrappedText(data.preview, 13, 'Regular', 640);
  bindFill(preview, 'text/secondary');
  const meta = makeText(data.meta, 12, 'Regular');
  bindFill(meta, 'text/muted');
  mainCol.appendChild(convTitle);
  mainCol.appendChild(preview);
  mainCol.appendChild(meta);

  const actions = figma.createAutoLayout('VERTICAL', { name: 'Actions', itemSpacing: 8 });
  const resumeBtn = makeBtn('Resume', false);
  resumeBtn.strokes = [{ type: 'SOLID', color: { r: 0.796, g: 0.835, b: 0.996 } }];
  bindFill(resumeBtn.children[0], 'text/brand');
  actions.appendChild(resumeBtn);
  actions.appendChild(makeBtn('Remove', false));

  headerRow.appendChild(mainCol);
  headerRow.appendChild(actions);
  card.appendChild(headerRow);
  mainCol.layoutSizingHorizontal = 'FILL';
  headerRow.layoutSizingHorizontal = 'FILL';

  const detailsGrid = figma.createAutoLayout('HORIZONTAL', { name: 'Case details', itemSpacing: 12, layoutWrap: 'WRAP' });

  const panelW = 300;
  const progressPanel = makeMiniPanel('Case Progress');
  progressPanel.appendChild(makeText(data.stage, 13, 'Regular'));
  progressPanel.appendChild(makeProgressBar(data.pct, panelW - 32));
  progressPanel.appendChild(makeWrappedText('Confidence: ' + data.confidence, 13, 'Regular', panelW - 32));
  progressPanel.appendChild(makeWrappedText('Next likely step: ' + data.nextStep, 13, 'Regular', panelW - 32));
  progressPanel.appendChild(makeBtn('Continue Case', true));
  progressPanel.resize(panelW, progressPanel.height);

  const lifePanel = makeMiniPanel('Case Lifecycle');
  const pillsRow = figma.createAutoLayout('HORIZONTAL', { name: 'Milestones', itemSpacing: 8, layoutWrap: 'WRAP' });
  data.milestones.forEach((m) => pillsRow.appendChild(makePill(m.label, m.state)));
  lifePanel.appendChild(pillsRow);
  pillsRow.layoutSizingHorizontal = 'FILL';
  const update = makeText('Update milestones', 13, 'Medium');
  bindFill(update, 'text/brand');
  lifePanel.appendChild(update);
  lifePanel.resize(panelW, lifePanel.height);

  const courtsPanel = makeMiniPanel('Courts Involved');
  data.courts.forEach((court) => {
    const courtItem = figma.createAutoLayout('VERTICAL', {
      name: 'Court',
      paddingLeft: 12,
      paddingRight: 12,
      paddingTop: 8,
      paddingBottom: 8,
      itemSpacing: 4,
      cornerRadius: 8,
      strokeWeight: 1,
    });
    courtItem.fills = [{ type: 'SOLID', color: { r: 0.973, g: 0.98, b: 0.988 } }];
    courtItem.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
    courtItem.appendChild(makeText(court.label, 13, 'Semi Bold'));
    if (court.note) {
      courtItem.appendChild(makeWrappedText(court.note, 12, 'Regular', panelW - 56));
    }
    courtsPanel.appendChild(courtItem);
    courtItem.layoutSizingHorizontal = 'FILL';
  });
  courtsPanel.resize(panelW, courtsPanel.height);

  detailsGrid.appendChild(progressPanel);
  detailsGrid.appendChild(lifePanel);
  detailsGrid.appendChild(courtsPanel);
  card.appendChild(detailsGrid);
  detailsGrid.layoutSizingHorizontal = 'FILL';

  return card;
}

const convSection = figma.createAutoLayout('VERTICAL', {
  name: 'Your Conversations',
  paddingLeft: 24,
  paddingRight: 24,
  paddingTop: 24,
  paddingBottom: 24,
  itemSpacing: 16,
  cornerRadius: 12,
  strokeWeight: 1,
});
convSection.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
convSection.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
bindFill(convSection, 'bg/surface');
bindStroke(convSection, 'border/default');

const sectionTitle = makeText('Your Conversations', 18, 'Semi Bold');
bindFill(sectionTitle, 'text/primary');
convSection.appendChild(sectionTitle);

const conv1 = makeConversationCard({
  title: 'I need to file for divorce in New York City',
  preview: 'I need to file for divorce in New York City. My spouse and I agree on everything.',
  meta: 'June 30, 2026, 6:50 am · 4 messages · Uncontested matrimonial action in NYC Supreme Court',
  stage: 'Starting the Case · 7% complete',
  pct: 7,
  confidence: 'Moderate - A likely workflow path is identified',
  nextStep: 'Service - Serving court papers in New York',
  milestones: [
    { label: 'Eligibility', state: 'completed' },
    { label: 'Intake', state: 'current' },
    { label: 'Forms', state: 'default' },
    { label: 'Filed', state: 'default' },
    { label: 'Served', state: 'default' },
  ],
  courts: [
    { label: 'Supreme Court - Divorce', note: '' },
    { label: 'Family Court - Custody', note: 'May proceed alongside your divorce case.' },
  ],
});

const conv2 = makeConversationCard({
  title: 'Child support modification in Brooklyn',
  preview: 'I need to modify my existing child support order. Income changed last year.',
  meta: 'June 28, 2026, 2:15 pm · 6 messages · Child support modification in Family Court',
  stage: 'Gathering Information · 22% complete',
  pct: 22,
  confidence: 'Low - More details needed to confirm workflow',
  nextStep: 'Financial disclosure - Upload recent pay stubs',
  milestones: [
    { label: 'Eligibility', state: 'completed' },
    { label: 'Intake', state: 'completed' },
    { label: 'Forms', state: 'current' },
    { label: 'Filed', state: 'default' },
  ],
  courts: [{ label: 'Family Court - Child Support', note: 'Kings County Family Court' }],
});

convSection.appendChild(conv1);
convSection.appendChild(conv2);
main.appendChild(convSection);
convSection.layoutSizingHorizontal = 'FILL';
conv1.layoutSizingHorizontal = 'FILL';
conv2.layoutSizingHorizontal = 'FILL';

const docsCard = figma.createAutoLayout('VERTICAL', {
  name: 'Generated Documents',
  paddingLeft: 24,
  paddingRight: 24,
  paddingTop: 24,
  paddingBottom: 24,
  itemSpacing: 12,
  cornerRadius: 12,
  strokeWeight: 1,
});
docsCard.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
docsCard.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
bindFill(docsCard, 'bg/surface');
bindStroke(docsCard, 'border/default');
const docsTitle = makeText('Generated Documents', 18, 'Semi Bold');
bindFill(docsTitle, 'text/primary');
docsCard.appendChild(docsTitle);
const docEmpty = makeWrappedText('No documents generated yet. Complete intake in a conversation to generate forms.', 13, 'Regular', 900);
bindFill(docEmpty, 'text/muted');
docsCard.appendChild(docEmpty);
main.appendChild(docsCard);
docsCard.layoutSizingHorizontal = 'FILL';

const rootFrame = await figma.getNodeByIdAsync('148:25');
if (rootFrame && 'height' in rootFrame) {
  rootFrame.resize(1440, Math.max(1400, main.y + main.height + 80));
}

await main.screenshot({ scale: 0.45 });

return {
  createdNodeIds: [convSection.id, conv1.id, conv2.id, docsCard.id],
  mutatedNodeIds: [main.id, rootFrame ? rootFrame.id : null],
};
