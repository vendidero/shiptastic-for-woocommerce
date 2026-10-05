import {
  computeFactor,
  factoredInteger,
  toOriginal
} from "./index-fs4vjjqp.js";

// src/2d/score.ts
class Score {
  score1;
  score2;
  static MAX_INT = Number.MAX_SAFE_INTEGER;
  static MAX = new Score(Score.MAX_INT, Score.MAX_INT);
  constructor(score1, score2) {
    this.score1 = score1;
    this.score2 = score2;
  }
  isBlank() {
    return this.score1 === Score.MAX_INT;
  }
  isBetterThan(other) {
    if (this.score1 < other.score1)
      return true;
    if (this.score1 === other.score1 && this.score2 < other.score2)
      return true;
    return false;
  }
  subtract(delta) {
    return new Score(this.score1 - delta, this.score2 - delta);
  }
}

// src/2d/heuristics/base.ts
class BaseHeuristic {
  findPosition(boxWidth, boxHeight, freeRects, constrainRotation) {
    let bestScore = Score.MAX;
    let bestPlacement = null;
    for (const freeRect of freeRects) {
      const normal = this.tryFit(freeRect, boxWidth, boxHeight);
      if (normal && normal.score.isBetterThan(bestScore)) {
        bestScore = normal.score;
        bestPlacement = normal;
      }
      if (!constrainRotation) {
        const rotated = this.tryFit(freeRect, boxHeight, boxWidth);
        if (rotated && rotated.score.isBetterThan(bestScore)) {
          bestScore = rotated.score;
          bestPlacement = rotated;
        }
      }
    }
    return bestPlacement;
  }
  tryFit(freeRect, rectWidth, rectHeight) {
    if (rectWidth > freeRect.width || rectHeight > freeRect.height) {
      return null;
    }
    const score = this.calculateScore(freeRect, rectWidth, rectHeight);
    return {
      x: freeRect.x,
      y: freeRect.y,
      width: rectWidth,
      height: rectHeight,
      score
    };
  }
}

// src/2d/heuristics/best-short-side-fit.ts
class BestShortSideFit extends BaseHeuristic {
  calculateScore(freeRect, rectWidth, rectHeight) {
    const leftOverHoriz = Math.abs(freeRect.width - rectWidth);
    const leftOverVert = Math.abs(freeRect.height - rectHeight);
    const shortSide = Math.min(leftOverHoriz, leftOverVert);
    const longSide = Math.max(leftOverHoriz, leftOverVert);
    return new Score(shortSide, longSide);
  }
}

// src/2d/free-rect-manager.ts
class FreeRectManager {
  rects;
  constructor(width, height) {
    this.rects = [{ x: 0, y: 0, width, height }];
  }
  getRects() {
    return this.rects;
  }
  clone() {
    const copy = Object.create(FreeRectManager.prototype);
    copy.rects = this.rects.map((r) => ({ ...r }));
    return copy;
  }
  insert(placed) {
    let numToProcess = this.rects.length;
    let i = 0;
    while (i < numToProcess) {
      if (this.splitFreeNode(this.rects[i], placed)) {
        this.rects.splice(i, 1);
        numToProcess--;
      } else {
        i++;
      }
    }
    this.pruneFreeList();
  }
  splitFreeNode(freeNode, usedNode) {
    if (usedNode.x >= freeNode.x + freeNode.width || usedNode.x + usedNode.width <= freeNode.x || usedNode.y >= freeNode.y + freeNode.height || usedNode.y + usedNode.height <= freeNode.y) {
      return false;
    }
    this.trySplitVertically(freeNode, usedNode);
    this.trySplitHorizontally(freeNode, usedNode);
    return true;
  }
  trySplitVertically(freeNode, usedNode) {
    if (usedNode.x < freeNode.x + freeNode.width && usedNode.x + usedNode.width > freeNode.x) {
      if (usedNode.y > freeNode.y && usedNode.y < freeNode.y + freeNode.height) {
        this.rects.push({
          x: freeNode.x,
          y: freeNode.y,
          width: freeNode.width,
          height: usedNode.y - freeNode.y
        });
      }
      if (usedNode.y + usedNode.height < freeNode.y + freeNode.height) {
        this.rects.push({
          x: freeNode.x,
          y: usedNode.y + usedNode.height,
          width: freeNode.width,
          height: freeNode.y + freeNode.height - (usedNode.y + usedNode.height)
        });
      }
    }
  }
  trySplitHorizontally(freeNode, usedNode) {
    if (usedNode.y < freeNode.y + freeNode.height && usedNode.y + usedNode.height > freeNode.y) {
      if (usedNode.x > freeNode.x && usedNode.x < freeNode.x + freeNode.width) {
        this.rects.push({
          x: freeNode.x,
          y: freeNode.y,
          width: usedNode.x - freeNode.x,
          height: freeNode.height
        });
      }
      if (usedNode.x + usedNode.width < freeNode.x + freeNode.width) {
        this.rects.push({
          x: usedNode.x + usedNode.width,
          y: freeNode.y,
          width: freeNode.x + freeNode.width - (usedNode.x + usedNode.width),
          height: freeNode.height
        });
      }
    }
  }
  pruneFreeList() {
    let i = 0;
    while (i < this.rects.length) {
      let j = i + 1;
      let pruned = false;
      while (j < this.rects.length) {
        if (this.isContainedIn(this.rects[i], this.rects[j])) {
          this.rects.splice(i, 1);
          pruned = true;
          break;
        }
        if (this.isContainedIn(this.rects[j], this.rects[i])) {
          this.rects.splice(j, 1);
        } else {
          j++;
        }
      }
      if (!pruned)
        i++;
    }
  }
  isContainedIn(rectA, rectB) {
    return rectA.x >= rectB.x && rectA.y >= rectB.y && rectA.x + rectA.width <= rectB.x + rectB.width && rectA.y + rectA.height <= rectB.y + rectB.height;
  }
}

// src/2d/scoreboard-entry.ts
class ScoreBoardEntry {
  binIndex;
  boxIndex;
  score;
  constructor(binIndex, boxIndex, score) {
    this.binIndex = binIndex;
    this.boxIndex = boxIndex;
    this.score = score;
  }
}

// src/2d/scoreboard.ts
function findBestEntry(bins, boxes, packedFlags) {
  let bestEntry = null;
  let bestScore = Score.MAX;
  for (let bi = 0;bi < bins.length; bi++) {
    const bin = bins[bi];
    for (let boxi = 0;boxi < boxes.length; boxi++) {
      if (packedFlags[boxi])
        continue;
      const box = boxes[boxi];
      if (box.width > bin.width && box.height > bin.width)
        continue;
      if (box.width > bin.height && box.height > bin.height)
        continue;
      const freeRectsCopy = bin.freeRects.clone();
      const placement = bin.heuristic.findPosition(box.width, box.height, freeRectsCopy.getRects(), box.constrainRotation);
      if (placement && placement.score.isBetterThan(bestScore)) {
        bestScore = placement.score;
        bestEntry = new ScoreBoardEntry(bi, boxi, placement.score);
      }
    }
  }
  return bestEntry;
}

// src/2d/packer.ts
function pack2D(options) {
  const { bins, boxes, heuristic, limit } = options;
  const defaultHeuristic = heuristic ?? new BestShortSideFit;
  const allValues = [];
  for (const bin of bins) {
    allValues.push(bin.width, bin.height);
  }
  for (const box of boxes) {
    allValues.push(box.width, box.height);
  }
  const factor = options.factor ?? computeFactor(allValues);
  const factoredBoxes = boxes.map((box) => {
    const fb = {
      width: factoredInteger(box.width, factor),
      height: factoredInteger(box.height, factor),
      ...box.constrainRotation !== undefined && { constrainRotation: box.constrainRotation }
    };
    return fb;
  });
  const workingBins = bins.map((bin) => {
    const w = factoredInteger(bin.width, factor);
    const h = factoredInteger(bin.height, factor);
    return {
      source: bin,
      width: w,
      height: h,
      freeRects: new FreeRectManager(w, h),
      heuristic: defaultHeuristic,
      packedBoxes: []
    };
  });
  const packedFlags = new Array(boxes.length).fill(false);
  let packedCount = 0;
  const maxPack = limit ?? boxes.length;
  while (packedCount < maxPack) {
    const entry = findBestEntry(workingBins, factoredBoxes, packedFlags);
    if (!entry)
      break;
    const bin = workingBins[entry.binIndex];
    const fBox = factoredBoxes[entry.boxIndex];
    const placement = bin.heuristic.findPosition(fBox.width, fBox.height, bin.freeRects.getRects(), fBox.constrainRotation);
    if (!placement)
      break;
    bin.freeRects.insert(placement);
    const sourceBox = boxes[entry.boxIndex];
    const rotated = placement.width !== fBox.width || placement.height !== fBox.height;
    const defactor = (v) => toOriginal(v, factor);
    bin.packedBoxes.push({
      width: rotated ? sourceBox.height : sourceBox.width,
      height: rotated ? sourceBox.width : sourceBox.height,
      x: defactor(placement.x),
      y: defactor(placement.y),
      rotated,
      sourceBox
    });
    packedFlags[entry.boxIndex] = true;
    packedCount++;
  }
  const packedBins = workingBins.map((wb) => {
    const binArea = wb.source.width * wb.source.height;
    const boxesArea = wb.packedBoxes.reduce((sum, b) => sum + b.width * b.height, 0);
    return {
      width: wb.source.width,
      height: wb.source.height,
      boxes: wb.packedBoxes,
      efficiency: binArea > 0 ? boxesArea * 100 / binArea : 0
    };
  });
  const unpackedBoxes = boxes.filter((_, i) => !packedFlags[i]);
  return { packedBins, unpackedBoxes };
}

class Packer2D {
  bins;
  heuristic;
  constructor(bins = [], heuristic) {
    this.bins = bins;
    this.heuristic = heuristic ?? new BestShortSideFit;
  }
  pack(boxes, options) {
    return pack2D({
      bins: this.bins,
      boxes,
      heuristic: this.heuristic,
      limit: options?.limit
    });
  }
}
// src/2d/heuristics/best-area-fit.ts
class BestAreaFit extends BaseHeuristic {
  calculateScore(freeRect, rectWidth, rectHeight) {
    const areaFit = freeRect.width * freeRect.height - rectWidth * rectHeight;
    const leftOverHoriz = Math.abs(freeRect.width - rectWidth);
    const leftOverVert = Math.abs(freeRect.height - rectHeight);
    const shortSide = Math.min(leftOverHoriz, leftOverVert);
    return new Score(areaFit, shortSide);
  }
}
// src/2d/heuristics/best-long-side-fit.ts
class BestLongSideFit extends BaseHeuristic {
  calculateScore(freeRect, rectWidth, rectHeight) {
    const leftOverHoriz = Math.abs(freeRect.width - rectWidth);
    const leftOverVert = Math.abs(freeRect.height - rectHeight);
    const longSide = Math.max(leftOverHoriz, leftOverVert);
    const shortSide = Math.min(leftOverHoriz, leftOverVert);
    return new Score(longSide, shortSide);
  }
}
// src/2d/heuristics/bottom-left.ts
class BottomLeft extends BaseHeuristic {
  calculateScore(freeRect, _rectWidth, rectHeight) {
    return new Score(freeRect.y + rectHeight, freeRect.x);
  }
}
export { Score, BestShortSideFit, pack2D, Packer2D, BestAreaFit, BestLongSideFit, BottomLeft };
