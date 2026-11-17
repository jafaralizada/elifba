/**
 * The `first_letter` scene, «İlk hərfi tap!» (docs/architecture.md §2):
 * «"alma" hansı hərflə başlayır?». The question band at the top holds the
 * word's picture in the `picture` style's tile; the items — letters — stand
 * in the band below in the pack's item style, and tapping the word's first
 * letter solves the round.
 */
import Phaser from 'phaser';
import type { Round, RoundProgress } from '../contract';
import { figureKey } from '../figures';
import { ITEM_STYLES } from '../styles/registry';
import type { ItemLayout, ItemStyle } from '../styles/types';
import type { SceneOptions } from './board-scene';
import { FindScene } from './find';
import { COUNT_LAYOUT } from './layouts';

const QUESTION_SIZE = 340;
const QUESTION_X = 960;
const QUESTION_Y = 298;

export class FirstLetterScene extends FindScene {
    private question: Phaser.GameObjects.Container | null = null;

    constructor(options: SceneOptions) {
        super(options, 'first_letter');
    }

    protected scenery(): void {
        this.options.style.scenery?.(this, COUNT_LAYOUT.items);
    }

    protected itemLayout(style: ItemStyle, count: number): ItemLayout {
        return style.layout(count, COUNT_LAYOUT.items);
    }

    protected ask(round: Round, progress: RoundProgress): void {
        this.dropQuestion(0);

        if (progress.solved) {
            return;
        }

        const url = round.prompt.image;
        const question = this.add.container(0, 0);

        this.question = question;

        if (url === null) {
            return;
        }

        this.loadPictures([url], () => {
            if (
                this.question !== question ||
                !question.active ||
                !this.textures.exists(figureKey(url))
            ) {
                return;
            }

            const picture = ITEM_STYLES.picture.draw(this, '', {
                width: QUESTION_SIZE,
                colour: this.palette[0],
                figure: figureKey(url),
            }).root;

            picture.setPosition(QUESTION_X, QUESTION_Y).setScale(0).setAlpha(0);
            question.add(picture);
            this.tweens.add({
                targets: picture,
                scale: 1,
                alpha: 1,
                delay: 150,
                duration: 420,
                ease: 'Back.easeOut',
            });
        });
    }

    protected answered(): void {
        this.dropQuestion(420);
    }

    private dropQuestion(delay: number): void {
        const old = this.question;

        if (old === null) {
            return;
        }

        this.question = null;
        this.tweens.add({
            targets: old,
            y: -80,
            alpha: 0,
            delay,
            duration: 450,
            ease: 'Cubic.easeIn',
            onComplete: () => old.destroy(),
        });
    }
}
