/**
 * The `dino` item style (docs/architecture.md §2, "Figures"): a dinosaur
 * from the pack's figures standing behind a spotted plate with its item's
 * text. Dinosaurs stomp up from below the board and land with a heavy
 * stomp, sway and stomp while they wait, rear and shake on a miss and stomp
 * away off the bottom when their round is over. The dinosaurs pack uses
 * `sparkle`, so a dinosaur hit correctly lifts away whole, never broken.
 */
import Phaser from 'phaser';
import type { RoundItem } from '../contract';
import { drawFigure, figureLayout } from './figure';
import type { FigureShape } from './figure';
import { BoardItem } from './item';
import type {
    ItemDrawing,
    ItemLook,
    ItemStyle,
    ItemViewOptions,
} from './types';

const STOMP = 14;

const SHAPE: FigureShape = {
    compact: 1.28,
    roomy: 1.52,
    maxFigure: 1.16,
    overlap: 0.2,
    maxWidth: 360,
    margin: 140,
    fill: 0.88,
    motion: { up: STOMP, down: 0 },
    restY: (index) => 1080 * 0.5 + (index % 2 === 0 ? -8 : 8),
    spots: true,
};

function draw(scene: Phaser.Scene, text: string, look: ItemLook): ItemDrawing {
    return drawFigure(scene, text, look, SHAPE);
}

export class Dino extends BoardItem {
    private stomping = false;

    constructor(
        scene: Phaser.Scene,
        item: RoundItem,
        options: ItemViewOptions,
    ) {
        const { x, y, width, height, colour, figure } = options;

        super(
            scene,
            item.id,
            colour,
            draw(scene, item.text, { width, height, colour, figure }),
            x,
            y,
        );
    }

    /** Stomps up from below the board and lands with a heavy thud. */
    enter(delay: number): void {
        const { scene, root } = this;

        root.y = scene.scale.height + this.drawing.height / 2;
        scene.tweens.add({
            targets: root,
            y: this.restY,
            delay,
            duration: 660,
            ease: 'Back.easeOut',
            onComplete: () => this.stomp(),
        });
    }

    /** A wrong dinosaur rears, shakes and stays. */
    wobble(): void {
        if (this.gone) {
            return;
        }

        this.scene.tweens.killTweensOf(this.inner);
        this.inner.setPosition(0, 0).setAngle(0).setScale(1);
        this.scene.tweens.add({
            targets: this.inner,
            x: [-16, 14, -10, 6, -3, 0],
            y: [-10, 0, -6, 0, 0, 0],
            angle: [-6, 5, -4, 2, -1, 0],
            duration: 560,
            ease: 'Sine.easeInOut',
            onComplete: () => this.swayAndStomp(),
        });
    }

    /** The round is over: one heavy step up, then stomps away off the bottom. */
    leave(delay: number): void {
        if (!this.finish()) {
            return;
        }

        const { scene, root } = this;
        const away = root.x < scene.scale.width / 2 ? -1 : 1;

        scene.tweens.add({
            targets: root,
            x: root.x + away * 44,
            y: root.y - 90,
            delay,
            duration: 300,
            ease: 'Quad.easeOut',
            onComplete: () =>
                scene.tweens.add({
                    targets: root,
                    x: root.x + away * 130,
                    y: scene.scale.height + this.drawing.height,
                    duration: 620,
                    ease: 'Quad.easeIn',
                    onComplete: () => this.destroy(),
                }),
        });
    }

    /** Lands with a heavy dinosaur stomp. */
    private stomp(): void {
        if (this.gone) {
            return;
        }

        const squash = 0.83;

        this.scene.tweens.add({
            targets: this.inner,
            scaleY: { from: squash, to: 1 },
            scaleX: { from: 1.1, to: 1 },
            y: { from: (this.drawing.height * (1 - squash)) / 2, to: 0 },
            duration: 320,
            ease: 'Back.easeOut',
            onComplete: () => this.swayAndStomp(),
        });
    }

    private swayAndStomp(): void {
        if (this.stomping) {
            return;
        }

        this.stomping = true;
        const tilt = Phaser.Math.FloatBetween(1.4, 2.4);

        this.idle([
            {
                targets: this.inner,
                angle: { from: -tilt, to: tilt },
                duration: Phaser.Math.Between(1800, 2500),
            },
            {
                targets: this.root,
                y: this.restY - STOMP,
                duration: 280,
                ease: 'Quad.easeOut',
                delay: Phaser.Math.Between(300, 1500),
                repeatDelay: Phaser.Math.Between(1500, 2600),
            },
        ]);
    }
}

export const dino: ItemStyle = {
    stagger: 130,
    layout: (count, band) => figureLayout(SHAPE, count, band),
    draw,
    create: (scene, item, options) => new Dino(scene, item, options),
};
