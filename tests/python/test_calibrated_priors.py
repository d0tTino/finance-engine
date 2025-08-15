import numpy as np


def brier_score(y_true, y_pred):
    y_true = np.asarray(y_true)
    y_pred = np.asarray(y_pred)
    return np.mean((y_pred - y_true) ** 2)


def test_calibrated_priors_improve_brier_score():
    x_train = np.array([0] * 50 + [1] * 50)
    y_train = np.array([0] * 50 + [1] * 50)

    naive_prob = y_train.mean()
    p0 = y_train[x_train == 0].mean()
    p1 = y_train[x_train == 1].mean()

    def predict_naive(x):
        return np.full_like(x, naive_prob, dtype=float)

    def predict_calibrated(x):
        return np.where(x == 1, p1, p0)

    x_val = np.array([0, 0, 0, 0, 0, 1, 1, 1, 1, 1])
    y_val = np.array([0, 0, 0, 0, 0, 1, 1, 1, 1, 0])

    naive_score = brier_score(y_val, predict_naive(x_val))
    calibrated_score = brier_score(y_val, predict_calibrated(x_val))

    assert calibrated_score < naive_score
