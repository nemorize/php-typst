#set page("a4", margin: 20mm)
#set text(font: "Nanum Gothic", size: 11pt)
#import "functions.typ": square, greeting

= PHP로 만드는 Typst 문서

#greeting("PHP")

외부 실행 파일 없이 PHP가 문법을 해석하고 PDF를 생성합니다.
*굵은 글씨*와 _기울임_, `raw text`를 사용할 수 있습니다.

== 함수와 수식

함수 계산: 7의 제곱은 #square(7) 입니다.
인라인 수식 $x^2 + y_1$과 블록 수식:

$ frac(a + b, 2) = sqrt(x) $

== 표

#table(columns: 2, inset: 6pt,
  [*기능*], [*상태*],
  [PHP 함수 평가], [동작],
  [로컬 모듈], [동작],
  [공식 Typst 전체 호환], [개발 중],
)

== 목록

- PHP 8.5 이상
- 외부 라이브러리와 실행 파일 없음
+ 첫 번째 단계
+ 두 번째 단계

#pagebreak()

= 두 번째 페이지

#align(center)[순수 PHP 컴파일러의 초기 구현입니다.]
